<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Workflows\DataTransferObjects\WebhookDelivery;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\MissingIdempotencyKeyException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

function hooked(array $trigger = [], ?string $subject = null): WorkflowDefinition
{
    return published('hook', graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'idempotency' => ['from' => 'header:Idempotency-Key'], ...$trigger]),
        node('done', 'end'),
    ], [['start', 'done']]), null, $subject);
}

function loadRoutes(): void
{
    require __DIR__.'/../../routes/webhooks.php';
    app('router')->getRoutes()->refreshNameLookups();
}

// A-8 (webhook variant)
it('starts one instance per webhook idempotency key', function (): void {
    $hook = hooked()->webhook_id ?? throw new LogicException;

    $first = Workflows::triggers()->webhook($hook, ['order' => 1], ['Idempotency-Key' => ['abc']]);
    $again = Workflows::triggers()->webhook($hook, ['order' => 1], ['idempotency-key' => 'abc']);

    expect($first->created)->toBeTrue()->and($again->created)->toBeFalse()
        ->and($again->instance?->is($first->instance))->toBeTrue()
        ->and($first->instance?->trigger_payload['trigger'])->toBe(['payload' => ['order' => 1]]);
});

it('reads the key from the payload and looks the subject up by a registered field', function (): void {
    $document = document(null, ['title' => 'INV-7']);
    $hook = hooked(['idempotency' => ['from' => 'payload:event.id'], 'subject' => ['lookup' => 'title', 'value' => ['ref' => 'payload.invoice']]], 'document')->webhook_id ?? throw new LogicException;

    $result = Workflows::triggers()->webhook($hook, ['event' => ['id' => 'evt_1'], 'invoice' => 'INV-7']);

    expect($result->instance?->subject_id)->toBe($document->id)
        ->and(fn () => Workflows::triggers()->webhook($hook, ['event' => ['id' => 'evt_2'], 'invoice' => 'INV-9']))->toThrow(SubjectOwnerMismatchException::class);
});

it('refuses a missing key and an unknown hook', function (): void {
    $hook = hooked()->webhook_id ?? throw new LogicException;

    expect(fn () => Workflows::triggers()->webhook($hook, []))->toThrow(MissingIdempotencyKeyException::class)
        ->and(fn () => Workflows::triggers()->webhook('01UNKNOWN', [], ['Idempotency-Key' => 'x']))->toThrow(UnknownDefinitionException::class);
});

// C-126: fail closed without signature middleware.
it('registers the route only with signature middleware', function (): void {
    config()->set('workflows.webhooks.enabled', 'on');
    loadRoutes();

    expect(Route::has('workflows.webhooks.start'))->toBeFalse();
    $this->artisan('workflows:doctor')->expectsOutputToContain('stays unregistered')->assertFailed();
});

it('serves the route behind the signature middleware', function (): void {
    Route::aliasMiddleware('signed-test', SignedTestMiddleware::class);
    config()->set('workflows.webhooks.enabled', 'on');
    config()->set('workflows.webhooks.verify_middleware', ['signed-test']);
    config()->set('workflows.webhooks.max_bytes', 1024);
    loadRoutes();

    $hook = hooked()->webhook_id ?? throw new LogicException;

    $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], ['Idempotency-Key' => 'k1'])->assertStatus(401);

    $headers = ['Idempotency-Key' => 'k1', 'X-Signed' => 'yes'];
    $created = $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], $headers)->assertStatus(202)->json();
    $duplicate = $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], $headers)->assertStatus(202)->json();

    expect($created['created'])->toBeTrue()
        ->and($duplicate)->toBe(['instance' => $created['instance'], 'created' => false])
        ->and(WorkflowInstance::query()->count())->toBe(1);

    $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], ['X-Signed' => 'yes'])->assertStatus(422)->assertJson(['code' => 'missing_idempotency_key']);
    $this->postJson('/workflows/hooks/01NOPE', ['a' => 1], $headers)->assertStatus(404);
    $this->postJson('/workflows/hooks/'.$hook, [1, 2], $headers)->assertStatus(422);
    $this->postJson('/workflows/hooks/'.$hook, ['big' => str_repeat('x', 2000)], $headers)->assertStatus(413);

    Workflows::definition('hook')->retire();
    $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], ['Idempotency-Key' => 'k2', 'X-Signed' => 'yes'])->assertStatus(404);
});

final class SignedTestMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $request->header('X-Signed') === 'yes' ? $next($request) : new JsonResponse(['code' => 'unsigned'], 401);
    }
}

it('needs webhooks-for-laravel for an endpoint-wired trigger', function (): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'endpoint' => 5]),
        node('done', 'end'),
    ], [['start', 'done']]), null);

    expect($report->find(ValidationCode::AdapterMissing, 'start')?->params)->toBe(['type' => 'endpoint', 'package' => 'roundly-consulting/webhooks-for-laravel'])
        ->and(fn () => Workflows::triggers()->delivery(new WebhookDelivery(5, null, 'evt_1')))->toThrow(UnknownDefinitionException::class)
        ->and(Workflows::catalogue()->has('webhook'))->toBeFalse();
});
