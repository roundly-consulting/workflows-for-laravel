<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;

/**
 * A draft graph was saved (an addition).
 */
final readonly class DraftSaved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowDefinition $definition,
        public WorkflowVersion $version,
    ) {}
}
