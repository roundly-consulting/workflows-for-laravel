<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Wake;

use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Support\Engine\ReclaimReport;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Recovery: claims older than `wake.claim_ttl` go back to pending, running attempts whose lease
 * expired fail as `lease_expired` (retryable; a compare-and-set), and queued attempts whose job
 * was lost are pushed again.
 *
 * @internal
 */
final readonly class ReclaimAction
{
    public function __construct(
        private RecordStepResultAction $record,
        private EngineQueue $queue,
    ) {}

    public function execute(): ReclaimReport
    {
        $now = Clock::now();
        $stale = Clock::format($now->sub(WorkflowsConfig::claimTtl()));
        $batch = WorkflowsConfig::wakeBatch();

        $waitModel = WorkflowModels::wait();
        $escalationModel = WorkflowModels::escalation();
        $runModel = WorkflowModels::stepRun();

        $claims = $waitModel::query()
            ->where('status', WaitStatus::Claimed->value)
            ->where('claimed_at', '<', $stale)
            ->update(['status' => WaitStatus::Pending->value, 'claim_token' => null, 'updated_at' => Clock::format($now)]);

        $claims += $escalationModel::query()
            ->where('status', EscalationStatus::Claimed->value)
            ->where('claimed_at', '<', $stale)
            ->update(['status' => EscalationStatus::Pending->value, 'claim_token' => null, 'updated_at' => Clock::format($now)]);

        $leases = 0;

        $expired = $runModel::query()
            ->where('status', StepRunStatus::Running->value)
            ->where('lease_until', '<', Clock::format($now))
            ->limit($batch)
            ->get();

        foreach ($expired as $run) {
            if ($this->record->failExpired(Keys::of($run), StepFailure::transient(FailureCode::LeaseExpired, Messages::get('failures.lease_expired')))) {
                $leases++;
            }
        }

        $requeued = 0;

        $lost = $runModel::query()
            ->where('status', StepRunStatus::Queued->value)
            ->where(static fn ($query) => $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', Clock::format($now)))
            ->where(static fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', Clock::format($now->sub(WorkflowsConfig::requeueAfter()))))
            ->whereNotIn('id', $waitModel::query()->where('kind', WaitKind::Retry->value)->where('status', WaitStatus::Pending->value)->select('step_run_id'))
            ->limit($batch)
            ->get();

        foreach ($lost as $run) {
            if (($run->dispatched_at !== null || $run->created_at === null || $run->created_at->lessThan($now->sub(WorkflowsConfig::requeueAfter())))) {
                $this->queue->runStep($run);
                $requeued++;
            }
        }

        return new ReclaimReport($claims, $leases, $requeued);
    }
}
