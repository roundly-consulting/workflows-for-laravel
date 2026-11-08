<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An instance started. `actor` is the instance itself (audit logs name it); `runAs` is who it runs as.
 */
final readonly class InstanceStarted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
