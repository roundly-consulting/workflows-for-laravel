<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Clock;

// Chat review C-7: `wake($now)` acts at `$now` throughout — what it reclaims, the time it stamps on
// its claims, and a retry it fires runs in the same pass.

final class RegressionOnceBusyStep extends StepType
{
    public static int $calls = 0;

    public function key(): string
    {
        return 'regression.once_busy';
    }

    public function label(): string
    {
        return 'Once busy';
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
        return [Outcome::of('next')];
    }

    public function handle(StepContext $context): StepResult
    {
        if (++self::$calls === 1) {
            throw RetryableStepException::because('busy', 'Try later.');
        }

        return StepResult::complete('next');
    }
}

function wakeNowClock(string $at): void
{
    Carbon::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
}

beforeEach(function (): void {
    wakeNowClock('2026-11-02 09:00:00');
    RegressionOnceBusyStep::$calls = 0;
    Workflows::catalogue()->register(new RegressionOnceBusyStep);
});

it('runs a retry that wake($now) fires in the same pass', function (): void {
    config()->set('workflows.retry.default', ['max_attempts' => 3, 'backoff' => [1800], 'jitter' => 0]);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('busy', 'regression.once_busy'),
        node('done', 'end'),
    ], [['start', 'busy'], ['busy', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $retry = WorkflowWait::query()->where('kind', WaitKind::Retry->value)->sole();
    expect($retry->due_at?->toDateTimeString())->toBe('2026-11-02 09:30:00');

    $report = Workflows::wake(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));

    expect($report->retries)->toBe(1)
        ->and($retry->refresh()->status)->toBe(WaitStatus::Fired)
        ->and($retry->claimed_at?->toDateTimeString())->toBe('2026-11-02 10:00:00')
        ->and($retry->fired_at?->toDateTimeString())->toBe('2026-11-02 10:00:00')
        ->and(RegressionOnceBusyStep::$calls)->toBe(2)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

it('reclaims what has expired at the given time', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    Queue::fake();
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('busy', 'regression.once_busy'),
        node('done', 'end'),
    ], [['start', 'busy'], ['busy', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('instance_id', $instance->id)->where('node_id', 'busy')->sole();
    $run->update(['status' => StepRunStatus::Running, 'claim_token' => 'CRASHED', 'lease_until' => Clock::now()->addMinutes(10), 'started_at' => Clock::now()]);
    $stale = WorkflowWait::factory()->create(['status' => WaitStatus::Claimed, 'claimed_at' => Clock::now(), 'due_at' => Clock::now()->addDay()]);

    $report = Workflows::wake(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));

    expect($report->reclaimed)->toBe(2)
        ->and($run->refresh()->status)->toBe(StepRunStatus::Failed)
        ->and($run->error_code)->toBe('lease_expired')
        ->and($stale->refresh()->status)->toBe(WaitStatus::Pending);
});
