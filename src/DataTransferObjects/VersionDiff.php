<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * A structural comparison of two versions of one definition, for a compare view: nodes added,
 * removed, changed and moved (`ui` only), edges added, removed and relabelled, and the trigger
 * and settings leaves that differ.
 */
final readonly class VersionDiff
{
    /**
     * @param  list<string>  $nodesAdded
     * @param  list<string>  $nodesRemoved
     * @param  list<NodeChange>  $nodesChanged
     * @param  list<string>  $nodesMoved
     * @param  list<EdgeReference>  $edgesAdded
     * @param  list<EdgeReference>  $edgesRemoved
     * @param  list<EdgeChange>  $edgesRelabelled
     * @param  list<string>  $triggerChanges
     * @param  list<string>  $settingsChanges
     */
    public function __construct(
        public int $from,
        public int $to,
        public bool $identical,
        public array $nodesAdded = [],
        public array $nodesRemoved = [],
        public array $nodesChanged = [],
        public array $nodesMoved = [],
        public array $edgesAdded = [],
        public array $edgesRemoved = [],
        public array $edgesRelabelled = [],
        public array $triggerChanges = [],
        public array $settingsChanges = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'identical' => $this->identical,
            'nodes' => [
                'added' => $this->nodesAdded,
                'removed' => $this->nodesRemoved,
                'changed' => array_map(static fn (NodeChange $change): array => $change->toArray(), $this->nodesChanged),
                'moved' => $this->nodesMoved,
            ],
            'edges' => [
                'added' => array_map(static fn (EdgeReference $edge): array => $edge->toArray(), $this->edgesAdded),
                'removed' => array_map(static fn (EdgeReference $edge): array => $edge->toArray(), $this->edgesRemoved),
                'relabelled' => array_map(static fn (EdgeChange $edge): array => $edge->toArray(), $this->edgesRelabelled),
            ],
            'trigger' => $this->triggerChanges,
            'settings' => $this->settingsChanges,
        ];
    }
}
