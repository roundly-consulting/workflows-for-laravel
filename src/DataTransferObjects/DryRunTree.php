<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * Every way a graph can go (`PendingDryRun::explore()`): the walk taken with the outcomes assumed
 * so far, and — at the first node whose outcome was assumed — one subtree per outcome it can end
 * with. Leaves are complete paths.
 */
final readonly class DryRunTree
{
    /**
     * @param  array<string, self>  $branches  outcome => the walk that takes it
     */
    public function __construct(
        public DryRunResult $result,
        public ?string $branchesAt = null,
        public array $branches = [],
    ) {}

    /**
     * Every complete path, left to right.
     *
     * @return list<DryRunResult>
     */
    public function paths(): array
    {
        if ($this->branches === []) {
            return [$this->result];
        }

        $paths = [];

        foreach ($this->branches as $branch) {
            $paths = [...$paths, ...$branch->paths()];
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'result' => $this->result->toArray(),
            'branches_at' => $this->branchesAt,
            'branches' => array_map(static fn (self $branch): array => $branch->toArray(), $this->branches),
        ];
    }
}
