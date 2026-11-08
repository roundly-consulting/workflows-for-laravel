<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\WaitSpec;

// Dual review O-3 / F-5: a retried waiting step keeps escalating.

final class RegressionGateStep extends StepType
{
    public static bool $fail = true;

    public function key(): string
    {
        return 'regression.gate';
    }

    public function label(): string
    {
        return 'Gate';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function supportsEscalations(): bool
    {
        return true;
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
        return StepResult::wait(WaitSpec::event('gate.open', ['subject' => false]));
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        if (self::$fail) {
            throw StepFailedException::because('gate_broken', 'broken');
        }

        return StepResult::complete('next');
    }
}

final class RegressionFlakyWaitStep extends StepType
{
    public static int $resumes = 0;

    public function key(): string
    {
        return 'regression.flaky_wait';
    }

    public function label(): string
    {
        return 'Flaky wait';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function supportsEscalations(): bool
    {
        return true;
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
        return StepResult::wait(WaitSpec::event('flaky.go', ['subject' => false]));
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        if (self::$resumes++ === 0) {
            throw RetryableStepException::because('busy', 'Try later.');
        }

        return StepResult::wait(WaitSpec::event('flaky.done', ['subject' => false]));
    }
}

function escalationClock(string $at): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    Carbon\Carbon::setTestNow(CarbonImmutable::parse($at, 'UTC'));
}

function chaseCount(): int
{
    return WorkflowInstance::query()->whereIn('definition_id', fn ($query) => $query->select('id')->from('workflow_definitions')->where('key', 'chase'))->count();
}

it('re-arms the escalations of a step retried by hand', function (): void {
    escalationClock('2026-11-02 09:00:00');
    RegressionGateStep::$fail = true;
    Workflows::catalogue()->register(new RegressionGateStep);
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('gate', 'regression.gate', [], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]]),
        node('done', 'end'),
    ], [['start', 'gate'], ['gate', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::emit('gate.open');
    expect($instance->refresh()->status)->toBe(InstanceStatus::Failed);

    escalationClock('2026-11-02 10:30:00');
    RegressionGateStep::$fail = false;
    Workflows::instances()->retry($instance);
    expect($instance->refresh()->status)->toBe(InstanceStatus::Waiting)
        ->and(WorkflowEscalation::query()->where('status', EscalationStatus::Pending->value)->value('due_at')?->toDateTimeString())->toBe('2026-11-02 11:30:00');

    escalationClock('2026-11-02 12:00:00');
    Workflows::wake();

    expect(WorkflowEscalation::query()->where('status', EscalationStatus::Fired->value)->count())->toBe(1)
        ->and(chaseCount())->toBe(1);
});

it('keeps an escalation that comes due while the step waits for its next attempt', function (): void {
    escalationClock('2026-01-05 09:00:00');
    config()->set('workflows.retry.default', ['max_attempts' => 3, 'backoff' => [600], 'jitter' => 0]);
    RegressionFlakyWaitStep::$resumes = 0;
    Workflows::catalogue()->register(RegressionFlakyWaitStep::class);
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'regression.flaky_wait', [], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    // 09:55 the event arrives, resume() fails transiently: attempt 2 is due at 10:05.
    escalationClock('2026-01-05 09:55:00');
    Workflows::signal('flaky.go');
    expect($instance->refresh()->status)->toBe(InstanceStatus::Running);

    // 10:00 the escalation comes due between the attempts.
    escalationClock('2026-01-05 10:00:00');
    Workflows::wake();
    expect(WorkflowEscalation::query()->where('status', EscalationStatus::Cancelled->value)->count())->toBe(0);

    escalationClock('2026-01-05 10:05:00');
    Workflows::wake();
    expect($instance->refresh()->status)->toBe(InstanceStatus::Waiting);

    escalationClock('2026-01-05 10:10:00');
    Workflows::wake();

    expect(WorkflowEscalation::query()->pluck('status')->all())->toContain(EscalationStatus::Fired)
        ->and(chaseCount())->toBe(1);
});
