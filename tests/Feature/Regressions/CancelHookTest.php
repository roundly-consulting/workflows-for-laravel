<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\WaitSpec;

// Dual review O-13: cancel runs the step's cancel() hook whatever state its latest attempt is in.

final class RegressionCancelAwareStep extends StepType
{
    /** @var list<array<string, mixed>|null> */
    public static array $cancels = [];

    public function key(): string
    {
        return 'regression.cancel_aware';
    }

    public function label(): string
    {
        return 'Cancel aware';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function configSchema(): array
    {
        return ['type' => 'object'];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('received')];
    }

    public function handle(StepContext $context): StepResult
    {
        if ($context->stepRun()->attempt > 1) {
            throw RetryableStepException::because('busy', 'later');
        }

        return StepResult::wait(WaitSpec::event('cancel.go', ['subject' => false]), ['round' => 42]);
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        throw RetryableStepException::because('busy', 'later');
    }

    public function cancel(StepContext $context): void
    {
        self::$cancels[] = $context->stepRun()->output;
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-01-05 09:00:00');
    CarbonImmutable::setTestNow('2026-01-05 09:00:00');
    config()->set('workflows.retry.default', ['max_attempts' => 3, 'backoff' => [600], 'jitter' => 0]);
    RegressionCancelAwareStep::$cancels = [];
    Workflows::catalogue()->register(RegressionCancelAwareStep::class);
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('w', 'regression.cancel_aware'), node('done', 'end')], [['start', 'w'], ['w', 'done', 'received']]), null, null);
});

it('runs the cancel hook for a step in its retry backoff', function (): void {
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::signal('cancel.go');
    expect(StepRun::query()->where('node_id', 'w')->orderByDesc('attempt')->first()?->status)->toBe(StepRunStatus::Queued);

    Workflows::instances()->cancel($instance);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and(RegressionCancelAwareStep::$cancels)->toBe([['round' => 42]]);
});

it('runs the cancel hook for a queued retry that lost its earlier output', function (): void {
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $first = StepRun::query()->where('node_id', 'w')->sole();

    // A handle() retry starts without the earlier attempt's output: the hook still sees it.
    $first->status = StepRunStatus::Failed;
    $first->save();
    $retry = $first->replicate(['claim_token']);
    $retry->attempt = 2;
    $retry->status = StepRunStatus::Queued;
    $retry->output = null;
    $retry->save();

    Workflows::instances()->cancel($instance);

    expect(RegressionCancelAwareStep::$cancels)->toBe([['round' => 42]])
        ->and($retry->refresh()->status)->toBe(StepRunStatus::Cancelled)
        ->and($retry->output)->toBeNull();
});

it('runs the cancel hook for a step whose attempt is running', function (): void {
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('node_id', 'w')->sole();
    $run->status = StepRunStatus::Running;
    $run->save();

    Workflows::instances()->cancel($instance);

    expect(RegressionCancelAwareStep::$cancels)->toBe([['round' => 42]]);
});
