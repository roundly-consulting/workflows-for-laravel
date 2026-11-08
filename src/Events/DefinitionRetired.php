<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;

/**
 * A definition was retired: it starts nothing new (an addition).
 */
final readonly class DefinitionRetired implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowDefinition $definition,
    ) {}
}
