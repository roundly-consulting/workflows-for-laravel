<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Exceptions\InstanceNotRetryableException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

// Dual review O-8 / F-3: a start refused by the rate limit leaves its idempotency key free.

final class RegressionPassMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}

beforeEach(function (): void {
    config()->set('workflows.limits.max_starts_per_minute', 1);
    Carbon::setTestNow('2026-11-02 09:00:00');
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');
});

it('starts a refused trigger key once the window has room', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);

    Workflows::start('flow')->idempotencyKey('order-1')->dispatch();
    $refused = Workflows::start('flow')->idempotencyKey('order-2')->dispatch();

    expect($refused->created)->toBeFalse()
        ->and($refused->refused)->toBe('start_rate_exceeded')
        ->and($refused->wasRefused())->toBeTrue()
        ->and($refused->instance?->failure_code)->toBe('start_rate_exceeded');

    Carbon::setTestNow('2026-11-02 09:02:00');
    CarbonImmutable::setTestNow('2026-11-02 09:02:00');
    $again = Workflows::start('flow')->idempotencyKey('order-2')->dispatch();
    $duplicate = Workflows::start('flow')->idempotencyKey('order-2')->dispatch();

    expect($again->created)->toBeTrue()
        ->and($again->wasRefused())->toBeFalse()
        ->and($again->instance?->status)->toBe(InstanceStatus::Completed)
        ->and($duplicate->created)->toBeFalse()
        ->and($duplicate->instance?->is($again->instance))->toBeTrue();
});

it('tells the operator to start a refused instance again rather than retry it', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);

    Workflows::start('flow')->dispatch();
    $refused = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect(FailureCode::StartRateExceeded->retryable())->toBeFalse()
        ->and(fn () => Workflows::instances()->retry($refused))
        ->toThrow(InstanceNotRetryableException::class, 'This instance did not fail on a step, so there is nothing to retry: start it again instead.');
});

it('answers a rate-refused webhook with 429 so the sender retries', function (): void {
    Route::aliasMiddleware('regression-pass', RegressionPassMiddleware::class);
    config()->set('workflows.webhooks.enabled', 'on');
    config()->set('workflows.webhooks.verify_middleware', ['regression-pass']);
    require __DIR__.'/../../../routes/webhooks.php';
    app('router')->getRoutes()->refreshNameLookups();

    $hook = published('hook', graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'idempotency' => ['from' => 'header:Idempotency-Key']]),
        node('done', 'end'),
    ], [['start', 'done']]), null, null)->webhook_id;

    $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], ['Idempotency-Key' => 'k1'])->assertStatus(202);
    $this->postJson('/workflows/hooks/'.$hook, ['a' => 2], ['Idempotency-Key' => 'k2'])
        ->assertStatus(429)
        ->assertHeader('Retry-After', '60')
        ->assertJson(['code' => 'start_rate_exceeded', 'created' => false]);

    Carbon::setTestNow('2026-11-02 09:02:00');
    CarbonImmutable::setTestNow('2026-11-02 09:02:00');

    expect($this->postJson('/workflows/hooks/'.$hook, ['a' => 2], ['Idempotency-Key' => 'k2'])->assertStatus(202)->json('created'))->toBeTrue()
        ->and(WorkflowInstance::query()->where('status', 'completed')->count())->toBe(2);
});
