<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Wake;

use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\SignalKind;
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
 * Recovery: claims older than `wake.claim_ttl` go back to pending (a claimed event — its payload
 * stored — is delivered again instead), running attempts whose lease expired fail as
 * `lease_expired` (retryable; a compare-and-set), queued attempts whose job was lost are pushed
 * again, and a waiting step whose probe job was lost is probed again.
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

        $requeued = $this->redeliverEvents($stale, $batch);

        $claims = $waitModel::query()
            ->where('status', WaitStatus::Claimed->value)
            ->where('kind', '<>', WaitKind::Event->value)
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

        $requeued += $this->reprobe($batch);

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

    /**
     * A claimed event wait whose resume job was lost (or failed) is delivered again: its payload
     * is stored, and the event itself will not come twice. Re-claimed with a compare-and-set, so
     * overlapping wakes push it once; a duplicate resume finds the wait fired and does nothing.
     */
    private function redeliverEvents(string $stale, int $batch): int
    {
        $model = WorkflowModels::wait();
        $now = Clock::format(Clock::now());
        $redelivered = 0;

        $waits = $model::query()
            ->where('status', WaitStatus::Claimed->value)
            ->where('kind', WaitKind::Event->value)
            ->where('claimed_at', '<', $stale)
            ->limit($batch)
            ->get();

        foreach ($waits as $wait) {
            $claimed = $model::query()
                ->whereKey(Keys::of($wait))
                ->where('status', WaitStatus::Claimed->value)
                ->where('claim_token', $wait->claim_token)
                ->update(['claim_token' => (string) Str::ulid(), 'claimed_at' => $now, 'updated_at' => $now]);

            if ($claimed === 1) {
                $this->queue->resume($wait->step_run_id, Keys::of($wait), SignalKind::Event);
                $redelivered++;
            }
        }

        return $redelivered;
    }

    /**
     * A waiting step whose probe job was lost is probed again (the probe catches a source that
     * settled before the step's waits existed — nothing else would ever wake it).
     */
    private function reprobe(int $batch): int
    {
        $model = WorkflowModels::stepRun();
        $now = Clock::now();
        $reprobed = 0;

        $runs = $model::query()
            ->where('status', StepRunStatus::Waiting->value)
            ->where('probe_pushed_at', '<', Clock::format($now->sub(WorkflowsConfig::requeueAfter())))
            ->limit($batch)
            ->get();

        foreach ($runs as $run) {
            $pushed = $model::query()
                ->whereKey(Keys::of($run))
                ->where('status', StepRunStatus::Waiting->value)
                ->where('probe_pushed_at', Clock::format($run->probe_pushed_at ?? $now))
                ->update(['probe_pushed_at' => Clock::format($now), 'updated_at' => Clock::format($now)]);

            if ($pushed === 1) {
                $this->queue->resume(Keys::of($run), null, SignalKind::Probe);
                $reprobed++;
            }
        }

        return $reprobed;
    }
}
