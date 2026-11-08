<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;

/**
 * A template imported as a new draft definition, with the review flags left to resolve.
 */
final readonly class ImportResult
{
    /**
     * @param  list<ReviewFlag>  $flags
     * @param  array<string, string>  $idMap  old node id => new node id
     */
    public function __construct(
        public WorkflowDefinition $definition,
        public WorkflowVersion $version,
        public array $flags,
        public array $idMap,
    ) {}
}
