<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A failed instance was retried manually (an addition to the brief's events).
 */
final readonly class InstanceRetried implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
