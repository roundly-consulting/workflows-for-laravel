<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Wake;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\DataTransferObjects\WakeReport;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * `workflows:wake`, every minute: reclaim stale claims and leases, requeue lost jobs, then claim
 * the due timers, retries, timeouts, condition polls and escalations and hand each to its job
 * (which restores the owner context — wake never evaluates anything about a subject itself),
 * then the schedule triggers. Every claim is a compare-and-set, so overlapping wakes are safe.
 */
final readonly class WakeAction
{
    public const string LAST_RUN = 'workflows:wake:last';

    public function __construct(
        private ReclaimAction $reclaim,
        private EvaluateSchedulesAction $schedules,
        private EngineQueue $queue,
        private Repository $cache,
    ) {}

    public function execute(?CarbonInterface $now = null, ?int $limit = null): WakeReport
    {
        $now = $now === null ? Clock::now() : Clock::utc($now);
        $batch = min($limit ?? WorkflowsConfig::wakeBatch(), WorkflowsConfig::wakeBatch());

        $reclaimed = $this->reclaim->execute();
        $counts = ['timer' => 0, 'retry' => 0, 'timeout' => 0, 'condition' => 0];

        foreach ($this->claimWaits($now, $batch) as $wait) {
            $counts[$wait->kind->value] = ($counts[$wait->kind->value] ?? 0) + 1;

            match ($wait->kind) {
                WaitKind::Retry => $this->retry($wait),
                WaitKind::Timeout => $this->queue->resume($wait->step_run_id, Keys::of($wait), SignalKind::Timeout),
                WaitKind::Condition => $this->queue->resume($wait->step_run_id, Keys::of($wait), SignalKind::Condition),
                default => $this->queue->resume($wait->step_run_id, Keys::of($wait), SignalKind::Timer),
            };
        }

        $escalations = 0;

        foreach ($this->claimEscalations($now, $batch) as $escalation) {
            $escalations++;
            $this->queue->escalate(Keys::of($escalation));
        }

        $schedules = $this->schedules->execute($now);

        $this->cache->forever(self::LAST_RUN, $now->toIso8601String());

        return new WakeReport(
            timers: $counts['timer'],
            retries: $counts['retry'],
            timeouts: $counts['timeout'],
            polls: $counts['condition'],
            escalations: $escalations,
            schedules: $schedules,
            reclaimed: $reclaimed['claims'] + $reclaimed['leases'],
            requeued: $reclaimed['requeued'],
        );
    }

    /**
     * @return list<WorkflowWait>
     */
    private function claimWaits(CarbonImmutable $now, int $batch): array
    {
        $model = WorkflowModels::wait();

        $ids = $model::query()
            ->where('status', WaitStatus::Pending->value)
            ->whereIn('kind', [WaitKind::Timer->value, WaitKind::Retry->value, WaitKind::Timeout->value, WaitKind::Condition->value])
            ->where('due_at', '<=', Clock::format($now))
            ->orderBy('due_at')
            ->limit($batch)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        $token = (string) Str::ulid();

        $model::query()
            ->whereIn('id', $ids)
            ->where('status', WaitStatus::Pending->value)
            ->update(['status' => WaitStatus::Claimed->value, 'claim_token' => $token, 'claimed_at' => Clock::format(Clock::now()), 'updated_at' => Clock::format(Clock::now())]);

        return array_values($model::query()->where('claim_token', $token)->where('status', WaitStatus::Claimed->value)->orderBy('due_at')->get()->all());
    }

    /**
     * @return list<WorkflowEscalation>
     */
    private function claimEscalations(CarbonImmutable $now, int $batch): array
    {
        $model = WorkflowModels::escalation();

        $ids = $model::query()
            ->where('status', EscalationStatus::Pending->value)
            ->where('due_at', '<=', Clock::format($now))
            ->orderBy('due_at')
            ->limit($batch)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        $token = (string) Str::ulid();

        $model::query()
            ->whereIn('id', $ids)
            ->where('status', EscalationStatus::Pending->value)
            ->update(['status' => EscalationStatus::Claimed->value, 'claim_token' => $token, 'claimed_at' => Clock::format(Clock::now()), 'updated_at' => Clock::format(Clock::now())]);

        return array_values($model::query()->where('claim_token', $token)->where('status', EscalationStatus::Claimed->value)->get()->all());
    }

    private function retry(WorkflowWait $wait): void
    {
        $wait->status = WaitStatus::Fired;
        $wait->fired_at = Clock::now();
        $wait->save();

        $this->queue->runStep($wait->step_run_id);
    }
}
