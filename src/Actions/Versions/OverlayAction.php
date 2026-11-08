<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Versions;

use RoundlyConsulting\Workflows\DataTransferObjects\NodeOverlay;
use RoundlyConsulting\Workflows\DataTransferObjects\OverlayReport;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * The run overlay of a version: one grouped query over its running, waiting and failed
 * instances, by current node and status (index `winst_overlay_idx`).
 */
final readonly class OverlayAction
{
    public function execute(WorkflowVersion $version): OverlayReport
    {
        $model = WorkflowModels::instance();

        $rows = $model::query()
            ->toBase()
            ->selectRaw('current_node_id, status, count(*) as aggregate')
            ->where('version_id', $version->getKey())
            ->whereIn('status', [InstanceStatus::Running->value, InstanceStatus::Waiting->value, InstanceStatus::Failed->value])
            ->whereNull('deleted_at')
            ->groupBy('current_node_id', 'status')
            ->get();

        $nodes = [];
        $total = 0;

        foreach ($rows as $row) {
            $node = isset($row->current_node_id) && is_string($row->current_node_id) ? $row->current_node_id : null;
            $status = isset($row->status) && is_string($row->status) ? $row->status : null;
            $count = isset($row->aggregate) && is_numeric($row->aggregate) ? (int) $row->aggregate : 0;

            if ($node === null || $status === null) {
                continue;
            }

            $nodes[$node] ??= ['running' => 0, 'waiting' => 0, 'failed' => 0];
            $nodes[$node][$status] = $count;
            $total += $count;
        }

        ksort($nodes);

        return new OverlayReport(
            version: $version->number,
            nodes: array_map(
                static fn (string $node, array $counts): NodeOverlay => new NodeOverlay($node, $counts['running'], $counts['waiting'], $counts['failed']),
                array_keys($nodes),
                $nodes,
            ),
            total: $total,
        );
    }
}
