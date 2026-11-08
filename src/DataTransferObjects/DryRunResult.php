<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * What a dry run found: whether the graph is valid and the trigger would start, the path taken,
 * the side effects a real run would have, and how the walk ended (`end`, `limit`, `invalid`,
 * `not_started`).
 */
final readonly class DryRunResult
{
    /**
     * @param  list<DryRunStep>  $path
     * @param  list<WouldBeAction>  $actions
     */
    public function __construct(
        public bool $valid,
        public bool $started,
        public array $path,
        public array $actions,
        public ?string $endedAt,
        public string $endedBy,
        public ValidationReport $report,
        public ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_map(static fn (DryRunStep $step): string => $step->node, $this->path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'started' => $this->started,
            'reason' => $this->reason,
            'path' => array_map(static fn (DryRunStep $step): array => $step->toArray(), $this->path),
            'actions' => array_map(static fn (WouldBeAction $action): array => $action->toArray(), $this->actions),
            'ended_at' => $this->endedAt,
            'ended_by' => $this->endedBy,
            'report' => $this->report->toArray(),
        ];
    }
}
