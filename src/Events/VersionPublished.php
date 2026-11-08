<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;

/**
 * A version was published and became the definition's current one.
 */
final readonly class VersionPublished implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowDefinition $definition,
        public WorkflowVersion $version,
        public ?Model $publisher,
    ) {}
}
