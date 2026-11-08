<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\Enums\InboundStatus;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;
use RoundlyConsulting\Workflows\Bridges\Webhooks\WebhooksBridge;
use RoundlyConsulting\Workflows\DataTransferObjects\WebhookDelivery;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Exceptions\EndpointOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\MissingIdempotencyKeyException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\Trigger;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\Webhooks\Fixtures\HostWorkflowsHandler;

/**
 * A definition whose webhook trigger names a webhooks-package inbound endpoint.
 *
 * @param  array<string, mixed>  $trigger
 */
function inboundWired(int|string|null $endpoint, ?Model $owner = null, string $key = 'intake', array $trigger = [], ?string $subject = null): WorkflowDefinition
{
    return published($key, graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'endpoint' => $endpoint, ...$trigger]),
        node('done', 'end'),
    ], [['start', 'done']]), $owner, $subject);
}

function inboundEndpoint(?Model $owner = null, ?string $handler = 'workflows'): InboundEndpoint
{
    return $owner === null
        ? Webhooks::inbound()->create(handler: $handler)->endpoint
        : Webhooks::for($owner)->inbound()->create(handler: $handler)->endpoint;
}

/**
 * The handler call the bridge makes, without HTTP or the queue.
 *
 * @param  array<array-key, mixed>  $payload
 */
function inboundRun(InboundEndpoint $endpoint, string $deliveryId = 'evt_1', array $payload = ['type' => 'invoice.paid'], bool $replay = false): void
{
    app(HandlerRegistry::class)->resolve('workflows')->handle(new InboundWebhook($deliveryId, $endpoint, 'invoice.paid', $payload, [], now()->toImmutable(), $replay, $replay ? 2 : 1, 1));
}

it('registers the workflows handler alias when webhooks-for-laravel is installed', function (): void {
    expect(app(HandlerRegistry::class)->has('workflows'))->toBeTrue()
        ->and(app(HandlerRegistry::class)->resolve('workflows'))->toBeInstanceOf(InboundHandler::class);
});

it('switches the handler alias off or renames it', function (mixed $value, ?string $alias): void {
    $registry = app(HandlerRegistry::class);
    $registry->forget('workflows');
    config()->set('workflows.bridges.webhooks.handler', $value);

    WebhooksBridge::register(app());

    expect($registry->has('workflows'))->toBe($alias === 'workflows')
        ->and($alias === null ? $registry->aliases() : $registry->has($alias))->toBe($alias === null ? [] : true);
})->with([
    'null' => [null, null],
    'false' => [false, null],
    'off' => ['off', null],
    'blank = default' => ['', 'workflows'],
    'renamed' => ['flows', 'flows'],
]);

it('keeps the default alias for a config published before the key existed', function (): void {
    $registry = app(HandlerRegistry::class);
    $registry->forget('workflows');
    config()->set('workflows.bridges', ['lifecycle' => ['emit_transitions' => 'off']]);

    WebhooksBridge::register(app());

    expect($registry->has('workflows'))->toBeTrue();
});

it('refuses an invalid handler alias, naming the key', function (): void {
    config()->set('workflows.bridges.webhooks.handler', 'Not An Alias');

    expect(fn () => WebhooksBridge::register(app()))->toThrow(InvalidWorkflowsConfigurationException::class, 'workflows.bridges.webhooks.handler');
});

it("leaves a host's own workflows handler in place", function (): void {
    $registry = app(HandlerRegistry::class);
    $registry->forget('workflows');
    config()->set('webhooks.handlers', ['workflows' => HostWorkflowsHandler::class]);

    WebhooksBridge::register(app());

    expect($registry->resolve('workflows'))->toBeInstanceOf(HostWorkflowsHandler::class);
});

it('registers nothing when webhooks-for-laravel is missing', function (): void {
    $registry = app(HandlerRegistry::class);
    $registry->forget('workflows');
    Bridges::pretendMissing(['webhooks']);

    WebhooksBridge::register(app());

    expect($registry->has('workflows'))->toBeFalse();
});

it('starts the wired workflow exactly once from a signed inbound delivery', function (): void {
    $company = company();
    $endpoint = inboundEndpoint($company);
    inboundWired($endpoint->id, $company);

    SignedWebhookRequest::for($endpoint)->id('msg_order_1')->payload(['type' => 'order.paid', 'data' => ['order' => 42]])->send()->assertSuccessful();
    // The sender retries the same message: webhooks dedupes it before any handler runs.
    SignedWebhookRequest::for($endpoint)->id('msg_order_1')->payload(['type' => 'order.paid', 'data' => ['order' => 42]])->send()->assertSuccessful();

    $instance = WorkflowInstance::query()->sole();
    $delivery = InboundDelivery::query()->sole();

    expect($instance)->toHaveCompletedVia(['start', 'done'])
        ->and($instance->trigger_type)->toBe(TriggerType::Webhook)
        // toEqual: MySQL's json column stores object keys in its own order.
        ->and($instance->trigger_payload['trigger'])->toEqual(['payload' => ['type' => 'order.paid', 'data' => ['order' => 42]]])
        ->and($delivery->status)->toBe(InboundStatus::Processed);
});

it('never re-runs a workflow when webhooks replays a delivery', function (): void {
    $company = company();
    $endpoint = inboundEndpoint($company);
    inboundWired($endpoint->id, $company);

    SignedWebhookRequest::for($endpoint)->id('msg_1')->payload(['type' => 'order.paid'])->send()->assertSuccessful();
    $first = WorkflowInstance::query()->sole();

    $replayed = Webhooks::inbound()->replay(InboundDelivery::query()->sole());

    expect($replayed->runs)->toBe(2)
        ->and($replayed->refresh()->status)->toBe(InboundStatus::Processed)
        ->and(WorkflowInstance::query()->sole()->is($first))->toBeTrue();
});

it('dedupes a replayed delivery to the first instance', function (): void {
    $endpoint = inboundEndpoint();
    inboundWired($endpoint->id, null, subject: null);

    inboundRun($endpoint, 'evt_9');
    $first = WorkflowInstance::query()->sole();

    $again = Workflows::triggers()->delivery(new WebhookDelivery($endpoint->id, null, 'evt_9', ['type' => 'invoice.paid']));
    inboundRun($endpoint, 'evt_9', replay: true);

    expect($again->created)->toBeFalse()
        ->and($again->instance?->is($first))->toBeTrue()
        ->and(WorkflowInstance::query()->count())->toBe(1);

    inboundRun($endpoint, 'evt_10');

    expect(WorkflowInstance::query()->count())->toBe(2);
});

// C-148
it('an_inbound_endpoint_only_starts_its_owners_definition', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    $globexEndpoint = inboundEndpoint($globex);
    // Acme's editor typed Globex's endpoint id into Acme's workflow.
    inboundWired($globexEndpoint->id, $acme, 'acme-intake');

    expect(fn () => inboundRun($globexEndpoint))->toThrow(EndpointOwnerMismatchException::class)
        ->and(WorkflowInstance::query()->count())->toBe(0);

    try {
        inboundRun($globexEndpoint);
    } catch (EndpointOwnerMismatchException $exception) {
        expect($exception->errorCode())->toBe('owner_mismatch')->and($exception->status())->toBe(404);
    }

    // Through the real pipeline the delivery fails in the webhooks log (after its tries).
    SignedWebhookRequest::for($globexEndpoint)->id('msg_x')->payload(['type' => 'order.paid'])->send();

    expect(WorkflowInstance::query()->count())->toBe(0)
        ->and(InboundDelivery::query()->sole()->status)->not->toBe(InboundStatus::Processed);

    // Globex's own workflow on its endpoint starts; Acme's foreign one never blocks it.
    inboundWired($globexEndpoint->id, $globex, 'globex-intake');
    inboundRun($globexEndpoint, 'evt_2');

    expect(WorkflowInstance::query()->sole()->definition?->key)->toBe('globex-intake');
});

it('matches owner-less endpoints with app-wide workflows only', function (): void {
    $company = company();
    $appEndpoint = inboundEndpoint();
    inboundWired($appEndpoint->id, null, 'app-intake', subject: null);

    inboundRun($appEndpoint);

    expect(WorkflowInstance::query()->sole()->definition?->key)->toBe('app-intake');

    // An owned workflow on an owner-less endpoint, and an app-wide one on an owned endpoint.
    $other = inboundEndpoint();
    inboundWired($other->id, $company, 'owned-on-app');
    $owned = inboundEndpoint($company);
    inboundWired($owned->id, null, 'app-on-owned', subject: null);

    expect(fn () => inboundRun($other, 'evt_2'))->toThrow(EndpointOwnerMismatchException::class)
        ->and(fn () => inboundRun($owned, 'evt_3'))->toThrow(EndpointOwnerMismatchException::class);
});

it('never falls back to app-wide workflows when the endpoint owner is gone', function (): void {
    $company = company();
    $endpoint = inboundEndpoint($company);
    inboundWired($endpoint->id, null, 'app-intake', subject: null);
    $company->delete();

    expect(fn () => inboundRun($endpoint->refresh()))->toThrow(EndpointOwnerMismatchException::class)
        ->and(WorkflowInstance::query()->count())->toBe(0);
});

it('refuses a delivery on an endpoint no active workflow names', function (): void {
    $endpoint = inboundEndpoint();

    expect(fn () => inboundRun($endpoint))->toThrow(UnknownDefinitionException::class, 'No active workflow starts from the webhook endpoint');

    inboundWired($endpoint->id, null, subject: null);
    Workflows::definition('intake')->retire();

    expect(fn () => inboundRun($endpoint, 'evt_2'))->toThrow(UnknownDefinitionException::class);
});

it('looks the subject up from the delivery payload in the owner scope', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    document($globex, ['title' => 'INV-7']);
    $mine = document($acme, ['title' => 'INV-7']);
    $endpoint = inboundEndpoint($acme);
    inboundWired($endpoint->id, $acme, trigger: ['subject' => ['lookup' => 'title', 'value' => ['ref' => 'payload.data.invoice']]], subject: 'document');

    inboundRun($endpoint, 'evt_1', ['data' => ['invoice' => 'INV-7']]);

    expect(WorkflowInstance::query()->sole()->subject_id)->toBe($mine->id)
        ->and(fn () => inboundRun($endpoint, 'evt_2', ['data' => ['invoice' => 'INV-404']]))->toThrow(SubjectOwnerMismatchException::class);
});

it('keeps the hook-id start working, and refuses it for an endpoint trigger without an idempotency key', function (): void {
    $endpoint = inboundEndpoint();
    $definition = inboundWired($endpoint->id, null, subject: null);
    $hook = $definition->webhook_id ?? throw new LogicException;

    expect(fn () => Workflows::triggers()->webhook($hook, ['a' => 1], ['Idempotency-Key' => 'k']))->toThrow(MissingIdempotencyKeyException::class);

    $both = inboundWired(inboundEndpoint()->id, null, 'both', ['idempotency' => ['from' => 'header:Idempotency-Key']], null);

    expect(Workflows::triggers()->webhook($both->webhook_id ?? '', ['a' => 1], ['Idempotency-Key' => 'k'])->created)->toBeTrue();
});

it('records a delivery under the fake without starting anything', function (): void {
    $endpoint = inboundEndpoint();
    inboundWired($endpoint->id, null, subject: null);
    $fake = Workflows::fake();

    inboundRun($endpoint);

    $fake->assertWebhookDeliveryReceived($endpoint->id);
    $fake->assertWebhookDeliveryNotReceived('999');

    expect(WorkflowInstance::query()->count())->toBe(0)
        ->and(fn () => $fake->assertWebhookDeliveryNotReceived($endpoint->id))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertWebhookDeliveryReceived('999'))->toThrow(AssertionFailedError::class)
        ->and($fake->recorded()[0]->context)->toMatchArray(['endpoint' => (string) $endpoint->id, 'delivery' => 'evt_1']);
});

it('wires an endpoint through the graph builder', function (): void {
    $endpoint = inboundEndpoint();
    Workflows::definitions()->create(key: 'built', name: 'Built');
    Workflows::definition('built')->edit(Workflows::graph()->trigger('start', Trigger::webhook(endpoint: $endpoint->id))->end('done')->chain('start', 'done'));
    Workflows::definition('built')->publish();

    inboundRun($endpoint);

    expect(WorkflowInstance::query()->sole()->definition?->key)->toBe('built');
});
