<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * What a start produced: the instance (a duplicate trigger key returns the first one, with
 * `created = false`), or nothing when the trigger condition skipped it. A start the rate limit
 * refused is `created = false` with `refused = 'start_rate_exceeded'` and its failed instance;
 * its idempotency key stays free, so the same start may be sent again.
 */
final readonly class StartResult
{
    public function __construct(
        public ?WorkflowInstance $instance,
        public bool $created,
        public ?string $skipped = null,
        public ?string $definition = null,
        public ?string $refused = null,
    ) {}

    public function wasSkipped(): bool
    {
        return $this->skipped !== null;
    }

    public function wasRefused(): bool
    {
        return $this->refused !== null;
    }
}
