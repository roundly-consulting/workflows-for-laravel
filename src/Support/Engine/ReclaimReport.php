<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

/**
 * What a reclaim pass recovered: stale wait and escalation claims, expired leases, and queued
 * steps whose job was re-pushed.
 *
 * @internal
 */
final readonly class ReclaimReport
{
    public function __construct(
        public int $claims = 0,
        public int $leases = 0,
        public int $requeued = 0,
    ) {}
}
