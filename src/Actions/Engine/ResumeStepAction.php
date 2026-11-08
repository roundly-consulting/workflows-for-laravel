<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Engine;

use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Events\InstanceResumed;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;
use RoundlyConsulting\Workflows\Support\Engine\ClaimedStep;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Engine\Waits;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * What ResumeStepJob runs: inside the owner context, lock the instance, then the step run (still
 * waiting), then the wait; a condition wait is re-evaluated (false: polled again later); the wait
 * fires and its siblings are cancelled (an event fired cancels its timeout, and vice versa); then
 * `resume()` runs and its result is recorded like any attempt. A probe fires no wait.
 *
 * @internal
 */
final readonly class ResumeStepAction
{
    public function __construct(
        private RestoresOwnerContext $context,
        private Attempts $attempts,
        private RecordStepResultAction $record,
        private Owners $owners,
    ) {}

    public function execute(int|string $stepRunId, int|string|null $waitId, SignalKind $kind): void
    {
        $runModel = WorkflowModels::stepRun();
        $instanceModel = WorkflowModels::instance();

        $run = $runModel::query()->find($stepRunId);
        $instance = $run instanceof StepRun ? $instanceModel::query()->find($run->instance_id) : null;

        if (! $run instanceof StepRun || ! $instance instanceof WorkflowInstance) {
            return;
        }

        $this->context->run(Runtime::owner($instance), fn () => $this->resume(Keys::of($instance), Keys::of($run), $waitId, $kind));
    }

    private function resume(int|string $instanceId, int|string $runId, int|string|null $waitId, SignalKind $kind): void
    {
        $claim = $this->claim($instanceId, $runId, $waitId, $kind);

        if ($claim === null) {
            return;
        }

        $instance = $claim->instance;
        $run = $claim->run;
        $signal = $claim->signal ?? new Signal(SignalKind::Probe);

        Events::dispatch(new InstanceResumed($instance, $run, $signal->kind, $instance, Runtime::actor($instance)));

        $outcome = $this->attempts->run($instance, $run, $signal);

        $this->record->execute(Keys::of($instance), Keys::of($run), $claim->token, $outcome->result, $outcome->transactional, $signal);
    }

    private function claim(int|string $instanceId, int|string $runId, int|string|null $waitId, SignalKind $kind): ?ClaimedStep
    {
        $instanceModel = WorkflowModels::instance();
        $runModel = WorkflowModels::stepRun();
        $waitModel = WorkflowModels::wait();
        $connection = (new $instanceModel)->getConnection();

        return $connection->transaction(function () use ($instanceModel, $runModel, $waitModel, $instanceId, $runId, $waitId, $kind): ?ClaimedStep {
            $instance = $instanceModel::query()->lockForUpdate()->find($instanceId);
            $run = $runModel::query()->lockForUpdate()->find($runId);
            $wait = $waitId === null ? null : $waitModel::query()->lockForUpdate()->find($waitId);

            if (! $instance instanceof WorkflowInstance || ! $run instanceof StepRun) {
                return null;
            }

            $now = Clock::now();

            // The step is busy (its probe or another resume is running): leave the wait claimed.
            // The running attempt's record delivers it once the step waits again, and retires it
            // with the step's other waits if the step moves on — a one-shot signal is never lost.
            if ($instance->status->isActive() && $run->status === StepRunStatus::Running) {
                return null;
            }

            if (! $instance->status->isActive() || $run->status !== StepRunStatus::Waiting) {
                if ($wait instanceof WorkflowWait && in_array($wait->status, [WaitStatus::Pending, WaitStatus::Claimed], true)) {
                    $wait->status = WaitStatus::Cancelled;
                    $wait->save();
                }

                return null;
            }

            $signal = new Signal(SignalKind::Probe);

            if ($kind !== SignalKind::Probe) {
                if (! $wait instanceof WorkflowWait || ! in_array($wait->status, [WaitStatus::Pending, WaitStatus::Claimed], true)) {
                    return null;
                }

                if ($wait->kind === WaitKind::Condition && ! $this->conditionHolds($instance, $wait)) {
                    $seconds = is_int($wait->payload['poll_seconds'] ?? null) ? $wait->payload['poll_seconds'] : 900;
                    $wait->status = WaitStatus::Pending;
                    $wait->claim_token = null;
                    $wait->due_at = $now->addSeconds(max(60, $seconds));
                    $wait->save();

                    return null;
                }

                $wait->status = WaitStatus::Fired;
                $wait->fired_at = $now;
                $wait->save();

                $waitModel::query()
                    ->where('step_run_id', Keys::of($run))
                    ->whereKeyNot(Keys::of($wait))
                    ->whereIn('status', [WaitStatus::Pending->value, WaitStatus::Claimed->value])
                    ->update(['status' => WaitStatus::Cancelled->value, 'updated_at' => Clock::format($now)]);

                $signal = new Signal(
                    kind: Waits::signalKind($wait->kind),
                    key: $wait->event_key,
                    payload: $wait->payload ?? [],
                    outcome: $wait->outcome,
                    waitId: Keys::of($wait),
                );
            }

            $token = (string) Str::ulid();

            $run->status = StepRunStatus::Running;
            $run->resume_signal = $signal->toArray();
            $run->claim_token = $token;
            $run->lease_until = $now->add(WorkflowsConfig::lease());
            $run->save();

            $instance->status = InstanceStatus::Running;
            $instance->lock_version++;
            $instance->save();

            return new ClaimedStep($instance, $run, $token, $signal);
        });
    }

    /**
     * Evaluated here, in the instance owner's context — never in wake's process or in the
     * request that touched the subject (which may run as another owner).
     */
    private function conditionHolds(WorkflowInstance $instance, WorkflowWait $wait): bool
    {
        $version = $instance->version;

        if (! $version instanceof WorkflowVersion || ! is_array($wait->condition)) {
            return false;
        }

        $owner = Runtime::owner($instance);
        $subject = $instance->subject_type === null ? null : Runtime::subject($instance);

        if ($instance->subject_type !== null && ($subject === null || ($instance->owner_type !== null && ! Owners::same($owner, $this->owners->ownerOf($subject))))) {
            return false;
        }

        return Evaluator::evaluate($wait->condition, Runtime::scope($instance, $version, $subject, $owner));
    }
}
