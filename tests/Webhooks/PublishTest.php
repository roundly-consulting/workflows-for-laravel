<?php

declare(strict_types=1);

use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Testing\FakeHostResolver;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Bridges;

beforeEach(function (): void {
    // Endpoint URLs are resolved (SSRF guard); never reach real DNS.
    FakeHostResolver::map(['hooks.example.com' => '93.184.216.34'])->install();
});

/**
 * @param  array<string, mixed>  $trigger
 * @return array<string, mixed>
 */
function endpointGraph(int|string|null $endpoint, array $trigger = ['trigger' => 'webhook']): array
{
    return graph([node('start', 'trigger', [...$trigger, 'endpoint' => $endpoint]), node('done', 'end')], [['start', 'done']]);
}

it('publishes an endpoint-wired webhook trigger without an idempotency key', function (): void {
    $endpoint = Webhooks::inbound()->create(handler: 'workflows')->endpoint;

    expect(Workflows::versions()->validate(endpointGraph($endpoint->id), null)->valid())->toBeTrue()
        ->and(Workflows::versions()->validate(graph([node('start', 'trigger', ['trigger' => 'webhook']), node('done', 'end')], [['start', 'done']]), null)->has(ValidationCode::InvalidConfig, 'start'))->toBeTrue();
});

it('refuses a second active workflow of the same owner on one endpoint', function (): void {
    $acme = company('Acme');
    $endpoint = Webhooks::for($acme)->inbound()->create(handler: 'workflows')->endpoint;
    published('first', endpointGraph($endpoint->id), $acme, null);

    Workflows::definitions()->create(key: 'second', name: 'Second', owner: $acme);
    Workflows::definition('second', $acme)->edit(endpointGraph((string) $endpoint->id));

    try {
        Workflows::definition('second', $acme)->publish();
        $this->fail('Expected InvalidGraphException.');
    } catch (InvalidGraphException $exception) {
        $error = $exception->validationReport()->find(ValidationCode::WebhookEndpointTaken, 'start');

        expect($error?->params)->toBe(['endpoint' => (string) $endpoint->id, 'definition' => 'first'])
            ->and($error?->message)->toBe('The webhook endpoint "'.$endpoint->id.'" already starts the workflow "first".');
    }

    // Republishing the same definition is fine; retiring it frees the endpoint.
    Workflows::definition('first', $acme)->edit(endpointGraph($endpoint->id));
    Workflows::definition('first', $acme)->publish();
    Workflows::definition('first', $acme)->retire();
    Workflows::definition('second', $acme)->publish();

    expect(Workflows::definition('second', $acme)->model()->isActive())->toBeTrue();
});

it("never lets another owner's workflow block an endpoint", function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    $endpoint = Webhooks::for($globex)->inbound()->create(handler: 'workflows')->endpoint;
    // Acme names Globex's endpoint first: refused at delivery (C-148), never a squat.
    published('squat', endpointGraph($endpoint->id), $acme, null);
    published('intake', endpointGraph($endpoint->id), $globex, null);

    expect(Workflows::definition('intake', $globex)->model()->isActive())->toBeTrue();
});

it('allows an endpoint only on a webhook trigger', function (): void {
    $report = Workflows::versions()->validate(endpointGraph(5, ['trigger' => 'manual']), null);

    expect($report->find(ValidationCode::InvalidConfig, 'start')?->path)->toBe('/endpoint');
});

it('flags the inbound and outbound endpoints on import', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    $inbound = Webhooks::for($acme)->inbound()->create(handler: 'workflows')->endpoint;
    $outbound = Webhooks::for($acme)->outbound()->create('https://hooks.example.com/in')->endpoint;
    published('intake', graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'endpoint' => $inbound->id]),
        node('send', 'webhook', ['endpoint' => $outbound->id, 'event' => 'order.received']),
        node('done', 'end'),
    ], [['start', 'send'], ['send', 'done', 'queued']]), $acme, null);

    $result = Workflows::versions()->import(Workflows::definition('intake', $acme)->export(), $globex) ?? throw new LogicException;
    $nodes = collect($result->version->graph['nodes'])->keyBy('id');

    expect(collect($result->flags)->map(fn ($flag): string => $flag->path)->all())->toContain('/config/endpoint')
        ->and(collect($result->flags)->pluck('node')->unique()->values()->all())->toEqualCanonicalizing([$result->idMap['start'], $result->idMap['send']])
        ->and($nodes[$result->idMap['start']]['config']['endpoint'])->toBeNull()
        ->and($nodes[$result->idMap['send']]['config']['endpoint'])->toBeNull()
        ->and(fn () => Workflows::definition('intake', $globex)->publish())->toThrow(InvalidGraphException::class);
});

it('fails the doctor for an endpoint trigger once webhooks-for-laravel is gone, and warns with the alias off', function (): void {
    $endpoint = Webhooks::inbound()->create(handler: 'workflows')->endpoint;
    published('intake', endpointGraph($endpoint->id), null, null);

    config()->set('workflows.bridges.webhooks.handler', null);
    $this->artisan('workflows:doctor')->expectsOutputToContain('the workflows handler alias is off');

    Bridges::pretendMissing(['webhooks']);
    $this->artisan('workflows:doctor')->expectsOutputToContain('[intake] starts from a webhook endpoint, which needs roundly-consulting/webhooks-for-laravel.')->assertFailed();
});
