<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Jobs\DeliverWebhook;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\FakeHostResolver;
use RoundlyConsulting\Webhooks\Testing\SentWebhook;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Bridges\Webhooks\WebhooksDeliverer;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;

beforeEach(function (): void {
    // Endpoint URLs are resolved (SSRF guard); never reach real DNS.
    FakeHostResolver::map(['hooks.example.com' => '93.184.216.34'])->install();
});

/**
 * A manual flow with one `webhook` step: `{endpoint, event, payload}`.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function outboundGraph(int|string|null $endpoint, string $event = 'document.approved', array $payload = ['title' => ['ref' => 'subject.title']]): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('send', 'webhook', ['endpoint' => $endpoint, 'event' => $event, 'payload' => $payload]),
        node('done', 'end'),
    ], [['start', 'send'], ['send', 'done', 'queued']]);
}

/**
 * @param  list<string>  $events
 */
function outboundEndpoint(?Model $owner = null, array $events = ['*']): OutboundEndpoint
{
    return $owner === null
        ? Webhooks::outbound()->create('https://hooks.example.com/in', events: $events)->endpoint
        : Webhooks::for($owner)->outbound()->create('https://hooks.example.com/in', events: $events)->endpoint;
}

function outboundRun(?Model $owner = null, string $key = 'notify-erp'): WorkflowInstance
{
    $subject = document($owner instanceof Company ? $owner : null, ['title' => 'Contract']);

    return Workflows::start($key, $subject, $owner)->dispatch()->instance ?? throw new LogicException;
}

it('delivers the webhook step through webhooks-for-laravel by default', function (): void {
    expect(Adapters::webhooks())->toBeInstanceOf(WebhooksDeliverer::class)
        ->and(Workflows::catalogue()->has('webhook'))->toBeTrue();
});

it('queues an outbound delivery and completes queued', function (): void {
    $company = company();
    $endpoint = outboundEndpoint($company);
    published('notify-erp', outboundGraph($endpoint->id), $company);
    $fake = Webhooks::fake();

    $instance = outboundRun($company);
    $run = $instance->stepRuns()->where('node_id', 'send')->sole();

    expect($instance)->toHaveCompletedVia(['start', 'send', 'done'])
        ->and($run->output['delivery'] ?? null)->toStartWith('msg_');

    $fake->assertSent($endpoint, 'document.approved', fn (array $payload): bool => $payload === ['title' => 'Contract']);
    $fake->assertSentTimes(1, $endpoint);
});

it('never queues a second delivery when the step is retried', function (): void {
    Queue::fake();
    $company = company();
    $endpoint = outboundEndpoint($company);
    published('notify-erp', outboundGraph($endpoint->id), $company);

    $instance = outboundRun($company);
    $send = $instance->stepRuns()->where('node_id', 'send')->sole();

    // A retried attempt of the same visit (a crashed worker, a lost lease).
    $again = StepRun::query()->create([...$send->only(['instance_id', 'node_id', 'node_type', 'visit', 'idempotency_key']), 'attempt' => 2, 'status' => StepRunStatus::Queued]);
    $instance->update(['status' => InstanceStatus::Running]);
    app(RunStepAction::class)->execute($again->id);

    expect($again->refresh()->status)->toBe(StepRunStatus::Completed)
        ->and(OutboundDelivery::query()->count())->toBe(1)
        ->and($again->output)->toBe($send->output);

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('never queues a second delivery even when once() was not recorded', function (): void {
    $company = company();
    $endpoint = outboundEndpoint($company);
    $deliverer = app(WebhooksDeliverer::class);

    Queue::fake();
    $first = $deliverer->deliver($company, (string) $endpoint->id, 'document.approved', ['a' => 1], 'step-key-1');
    $second = $deliverer->deliver($company, (string) $endpoint->id, 'document.approved', ['a' => 1], 'step-key-1');

    expect($second)->toBe($first)
        ->and(OutboundDelivery::query()->count())->toBe(1);
});

it('fails endpoint_not_found for a foreign, missing or malformed endpoint', function (Closure $endpoint): void {
    $acme = company('Acme');
    $globex = company('Globex');
    published('notify-erp', outboundGraph($endpoint($globex)), $acme);
    Webhooks::fake();

    $instance = outboundRun($acme);
    $run = $instance->stepRuns()->where('node_id', 'send')->sole();

    expect($instance)->toHaveFailedWith('endpoint_not_found')
        ->and($run->error_retryable)->toBeFalse()
        ->and(FailureCode::EndpointNotFound->retryable())->toBeFalse();
    Webhooks::assertNothingSent();
})->with([
    'another owner' => [fn (Model $globex): int|string => outboundEndpoint($globex)->id],
    'missing' => [fn (): int => 999999],
    'malformed' => [fn (): string => 'not-an-id'],
    'owner-less' => [fn (): int|string => outboundEndpoint()->id],
]);

it('matches an app-wide workflow with owner-less endpoints only', function (): void {
    $company = company();
    $appEndpoint = outboundEndpoint();
    published('notify-erp', outboundGraph($appEndpoint->id), null);
    published('to-owned', outboundGraph(outboundEndpoint($company)->id), null);
    $fake = Webhooks::fake();

    expect(outboundRun(null))->toHaveCompletedVia(['start', 'send', 'done'])
        ->and(outboundRun(null, 'to-owned'))->toHaveFailedWith('endpoint_not_found');

    $fake->assertSentTimes(1, $appEndpoint);
});

it('maps a refused send to a non-retryable code', function (Closure $arrange, string $code): void {
    $company = company();
    $endpoint = outboundEndpoint($company, ['document.*']);
    published('notify-erp', outboundGraph($endpoint->id), $company);
    Queue::fake();
    $arrange($endpoint);

    $instance = outboundRun($company);
    $run = $instance->stepRuns()->where('node_id', 'send')->sole();

    expect($instance)->toHaveFailedWith($code)
        ->and($run->error_retryable)->toBeFalse()
        ->and(OutboundDelivery::query()->count())->toBe(0);
})->with([
    'disabled endpoint' => [fn (OutboundEndpoint $endpoint) => Webhooks::outbound()->disable($endpoint), 'endpoint_not_accepting:disabled'],
    'not subscribed' => [fn (OutboundEndpoint $endpoint) => Webhooks::outbound()->update($endpoint, new OutboundEndpointChanges(events: ['invoice.*'])), 'endpoint_not_accepting:not_subscribed'],
    'event type removed from the allow-list after publish' => [fn () => config()->set('webhooks.outbound.event_types', ['invoice.paid']), 'invalid_event_type'],
    'payload too large' => [fn () => config()->set('webhooks.outbound.max_payload_bytes', 10), 'payload_too_large'],
]);

it('lets an operator retry after re-enabling a disabled endpoint', function (): void {
    expect(FailureCode::EndpointNotAccepting->retryable())->toBeTrue()
        ->and(FailureCode::InvalidEventType->retryable())->toBeFalse()
        ->and(FailureCode::PayloadTooLarge->retryable())->toBeFalse()
        ->and(FailureCode::fromStored('endpoint_not_accepting:disabled'))->toBe(FailureCode::EndpointNotAccepting)
        ->and(FailureCode::detail('endpoint_not_accepting:not_subscribed'))->toBe('not_subscribed')
        ->and(FailureCode::EndpointNotFound->message())->toBe('The webhook endpoint does not exist for the workflow owner.');
});

it('validates the event at publish against the webhooks type rule and allow-list', function (): void {
    $endpoint = outboundEndpoint()->id;

    expect(Workflows::versions()->validate(outboundGraph($endpoint), 'document')->valid())->toBeTrue()
        // The core rule lets a trailing newline through; the webhooks rule does not.
        ->and(Workflows::versions()->validate(outboundGraph($endpoint, "document.approved\n"), 'document')->has(ValidationCode::InvalidConfig, 'send'))->toBeTrue();

    config()->set('webhooks.outbound.event_types', ['document.approved', 'Not Valid']);

    expect(Workflows::versions()->validate(outboundGraph($endpoint), 'document')->valid())->toBeTrue()
        ->and(Workflows::versions()->validate(outboundGraph($endpoint, 'document.rejected'), 'document')->has(ValidationCode::UnknownWebhookEvent, 'send'))->toBeTrue();

    config()->set('webhooks.outbound.event_types', 'invoice.paid, document.rejected');

    expect(Workflows::versions()->validate(outboundGraph($endpoint, 'document.rejected'), 'document')->valid())->toBeTrue()
        ->and(Workflows::versions()->validate(outboundGraph($endpoint), 'document')->find(ValidationCode::UnknownWebhookEvent, 'send')?->message)
        ->toBe('The webhook event type "document.approved" is not in the allowed event types.');
});

it('skips the webhooks event rules for a host deliverer', function (): void {
    config()->set('webhooks.outbound.event_types', ['invoice.paid']);
    config()->set('workflows.adapters.webhooks', RecordingHostDeliverer::class);

    expect(Workflows::versions()->validate(outboundGraph('erp', 'document.approved'), 'document')->valid())->toBeTrue();
});

it('records the step output under the webhooks fake for a repeat', function (): void {
    $company = company();
    $endpoint = outboundEndpoint($company);
    $fake = Webhooks::fake();
    $deliverer = app(WebhooksDeliverer::class);

    $first = $deliverer->deliver($company, (string) $endpoint->id, 'document.approved', ['a' => 1], 'k1');
    $again = $deliverer->deliver($company, (string) $endpoint->id, 'document.approved', ['a' => 1], 'k1');

    expect($again)->toBe($first)
        ->and($fake->sent())->toHaveCount(1)
        ->and($fake->sent()->first())->toBeInstanceOf(SentWebhook::class);
});

final class RecordingHostDeliverer implements DeliversWebhooks
{
    public function deliver(?Model $owner, string $endpoint, string $event, array $payload, string $idempotencyKey): int|string
    {
        return 1;
    }
}

it('treats an id that cannot be a key of the configured type as not found, without a query', function (string $type): void {
    config()->set('webhooks.primary_key_type', $type);
    $deliverer = app(WebhooksDeliverer::class);
    DB::enableQueryLog();

    expect(fn () => $deliverer->deliver(null, 'not-a-key', 'document.approved', [], 'k1'))->toThrow(EndpointNotFoundException::class)
        ->and(DB::getQueryLog())->toBe([]);
})->with(['bigint', 'uuid', 'ulid']);
