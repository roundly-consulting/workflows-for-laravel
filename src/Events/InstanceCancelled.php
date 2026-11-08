<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An instance was cancelled.
 */
final readonly class InstanceCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public ?string $reason,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
