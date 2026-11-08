<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Workflows\Actions\Engine\ResumeStepAction;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\WaitSpec;

// Dual review O-6: an automatic retry of a failed resume resumes again with the same signal.

final class RegressionDecisionStep extends StepType
{
    public static int $resumes = 0;

    /** @var list<array{kind: string, payload: array<string, mixed>, output: array<string, mixed>|null}> */
    public static array $signals = [];

    public function key(): string
    {
        return 'regression.decision';
    }

    public function label(): string
    {
        return 'Decision';
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
        return [Outcome::of('approved'), Outcome::of('rejected')];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::wait(WaitSpec::event('decision.made', ['subject' => false]), ['round' => 7]);
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        self::$signals[] = ['kind' => $signal->kind->value, 'payload' => $signal->payload, 'output' => $context->stepRun()->output];

        if (++self::$resumes === 1) {
            throw new RuntimeException('deadlock found when trying to get lock');
        }

        return StepResult::complete((string) ($signal->payload['decision'] ?? 'approved'));
    }
}

beforeEach(function (): void {
    RegressionDecisionStep::$resumes = 0;
    RegressionDecisionStep::$signals = [];
    Workflows::catalogue()->register(new RegressionDecisionStep);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('decide', 'regression.decision'),
        node('done', 'end'),
    ], [['start', 'decide'], ['decide', 'done', 'approved'], ['decide', 'done', 'rejected']]), null, null);
});

it('resumes the retried attempt with the signal the failed resume received', function (): void {
    Carbon::setTestNow('2026-11-02 09:00:00');
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::emit('decision.made', payload: ['decision' => 'rejected']);

    Carbon::setTestNow('2026-11-02 09:05:00');
    CarbonImmutable::setTestNow('2026-11-02 09:05:00');
    Workflows::wake();

    $runs = StepRun::query()->where('node_id', 'decide')->orderBy('attempt')->get();

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($runs->pluck('outcome')->all())->toBe([null, 'rejected'])
        ->and(RegressionDecisionStep::$signals)->toHaveCount(2)
        ->and(RegressionDecisionStep::$signals[1]['kind'])->toBe(SignalKind::Event->value)
        ->and(RegressionDecisionStep::$signals[1]['payload'])->toBe(['decision' => 'rejected'])
        ->and(RegressionDecisionStep::$signals[1]['output'])->toBe(['round' => 7])
        ->and($runs->last()?->input)->toBe($runs->first()?->input);
});

it('resumes again after a worker died during the resume', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    Carbon::setTestNow('2026-11-02 09:00:00');
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');
    RegressionDecisionStep::$resumes = 1;

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $this->runWorkflows();
    Workflows::emit('decision.made', payload: ['decision' => 'rejected']);

    // The resume job claimed the step and its worker died: the run is left running.
    $run = StepRun::query()->where('node_id', 'decide')->sole();
    $wait = $run->waits()->sole();
    (fn () => $this->claim($run->instance_id, $run->id, $wait->id, SignalKind::Event))->call(app(ResumeStepAction::class));
    DB::table('jobs')->delete();

    Carbon::setTestNow('2026-11-02 10:00:00');
    CarbonImmutable::setTestNow('2026-11-02 10:00:00');
    Workflows::wake();
    $this->runWorkflows();
    Carbon::setTestNow('2026-11-02 10:30:00');
    CarbonImmutable::setTestNow('2026-11-02 10:30:00');
    Workflows::wake();
    $this->runWorkflows();

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(StepRun::query()->where('node_id', 'decide')->orderBy('attempt')->pluck('error_code')->all())->toBe(['lease_expired', null])
        ->and(RegressionDecisionStep::$signals[0]['payload'])->toBe(['decision' => 'rejected']);
});

it('runs handle() again on a manual retry', function (): void {
    config()->set('workflows.retry.default', ['max_attempts' => 1, 'backoff' => [30], 'jitter' => 0]);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::emit('decision.made', payload: ['decision' => 'rejected']);
    expect($instance->refresh()->status)->toBe(InstanceStatus::Failed);

    Workflows::instances()->retry($instance);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Waiting)
        ->and(RegressionDecisionStep::$signals)->toHaveCount(1);
});
