<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Maintenance;

use RoundlyConsulting\Workflows\DataTransferObjects\PruneReport;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * `workflows:prune`: force-deletes, in chunks, ended instance trees older than
 * `prune.instances_after` (their step runs, waits, escalations and effect keys cascade), expired
 * trigger keys and old dry-run logs. A failed parent is kept while a child still runs. Never
 * definitions or versions.
 */
final readonly class PruneAction
{
    public function execute(): PruneReport
    {
        $now = Clock::now();
        $instanceModel = WorkflowModels::instance();
        $cutoff = Clock::format($now->sub(WorkflowsConfig::pruneInstancesAfter()));
        $instances = 0;

        do {
            $batch = $instanceModel::query()
                ->withTrashed()
                ->whereIn('status', [InstanceStatus::Completed->value, InstanceStatus::Cancelled->value, InstanceStatus::Failed->value])
                ->where('ended_at', '<', $cutoff)
                ->whereNotIn('id', $instanceModel::query()
                    ->withTrashed()
                    ->whereNotNull('parent_instance_id')
                    ->whereIn('status', [InstanceStatus::Running->value, InstanceStatus::Waiting->value])
                    ->select('parent_instance_id'))
                ->orderBy('id')
                ->limit(500)
                ->get();

            foreach ($batch as $instance) {
                $instance->forceDelete();
                $instances++;
            }
        } while ($batch->count() === 500);

        $keyModel = WorkflowModels::idempotencyKey();
        $deleted = $keyModel::query()->whereNotNull('expires_at')->where('expires_at', '<', Clock::format($now))->delete();
        $keys = is_int($deleted) ? $deleted : 0;

        $dryRunModel = WorkflowModels::dryRun();
        $dryRuns = $dryRunModel::query()->withTrashed()->where('created_at', '<', Clock::format($now->sub(WorkflowsConfig::pruneDryRunsAfter())))->forceDelete();

        return new PruneReport($instances, $keys, is_int($dryRuns) ? $dryRuns : 0);
    }
}
