<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use RoundlyConsulting\Workflows\Enums\WorkflowOperation;

/**
 * One mutating call the fake recorded, with what it was asked.
 */
final readonly class RecordedOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public WorkflowOperation $operation,
        public array $context,
    ) {}
}
