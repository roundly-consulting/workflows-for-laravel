<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * What a start produced: the instance (a duplicate trigger key returns the first one, with
 * `created = false`), or nothing when the trigger condition skipped it.
 */
final readonly class StartResult
{
    public function __construct(
        public ?WorkflowInstance $instance,
        public bool $created,
        public ?string $skipped = null,
        public ?string $definition = null,
    ) {}

    public function wasSkipped(): bool
    {
        return $this->skipped !== null;
    }
}
