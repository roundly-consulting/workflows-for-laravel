<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * What one `workflows:prune` pass removed.
 */
final readonly class PruneReport
{
    public function __construct(
        public int $instances = 0,
        public int $idempotencyKeys = 0,
        public int $dryRuns = 0,
    ) {}
}
