<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An instance reached an end node.
 */
final readonly class InstanceCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
