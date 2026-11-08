<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Engine;

use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Events\StepStarted;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;
use RoundlyConsulting\Workflows\Support\Engine\ClaimedStep;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * What RunStepJob runs: inside the instance owner's context, TX-claim (lock the instance, then
 * the step run; exit on a cancelled instance or an already claimed run), run the attempt outside
 * the lock, then TX-record. One instance never advances twice in parallel.
 *
 * @internal
 */
final readonly class RunStepAction
{
    public function __construct(
        private RestoresOwnerContext $context,
        private Attempts $attempts,
        private RecordStepResultAction $record,
    ) {}

    public function execute(int|string $stepRunId): void
    {
        $runModel = WorkflowModels::stepRun();
        $instanceModel = WorkflowModels::instance();

        $run = $runModel::query()->find($stepRunId);
        $instance = $run instanceof StepRun ? $instanceModel::query()->find($run->instance_id) : null;

        if (! $run instanceof StepRun || ! $instance instanceof WorkflowInstance) {
            return;
        }

        $this->context->run(Runtime::owner($instance), fn () => $this->run(Keys::of($instance), Keys::of($run)));
    }

    private function run(int|string $instanceId, int|string $runId): void
    {
        $claim = $this->claim($instanceId, $runId);

        if ($claim === null) {
            return;
        }

        $instance = $claim->instance;
        $run = $claim->run;

        Events::dispatch(new StepStarted($instance, $run, $instance, Runtime::actor($instance)));

        // An automatic retry of a failed resume resumes with the signal that attempt received.
        $signal = Signal::fromArray($run->resume_signal);

        $outcome = $this->attempts->run($instance, $run, $signal);

        $this->record->execute(Keys::of($instance), Keys::of($run), $claim->token, $outcome->result, $outcome->transactional, $signal);
    }

    private function claim(int|string $instanceId, int|string $runId): ?ClaimedStep
    {
        $instanceModel = WorkflowModels::instance();
        $runModel = WorkflowModels::stepRun();
        $connection = (new $instanceModel)->getConnection();

        return $connection->transaction(static function () use ($instanceModel, $runModel, $instanceId, $runId): ?ClaimedStep {
            $instance = $instanceModel::query()->lockForUpdate()->find($instanceId);

            if (! $instance instanceof WorkflowInstance || ! $instance->status->isActive()) {
                return null;
            }

            $run = $runModel::query()->lockForUpdate()->find($runId);

            if (! $run instanceof StepRun || $run->status !== StepRunStatus::Queued) {
                return null;
            }

            $now = Clock::now();

            if ($run->scheduled_for !== null && $run->scheduled_for->greaterThan($now)) {
                return null;
            }

            $token = (string) Str::ulid();

            $run->status = StepRunStatus::Running;
            $run->claim_token = $token;
            $run->lease_until = $now->add(WorkflowsConfig::lease());
            $run->started_at = $now;
            $run->save();

            $instance->status = InstanceStatus::Running;
            $instance->current_node_id = $run->node_id;
            $instance->lock_version++;
            $instance->save();

            return new ClaimedStep($instance, $run, $token);
        });
    }
}
