<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;

/**
 * An instance started waiting on a step (timer, event, condition or approval).
 */
final readonly class InstanceWaiting implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<WorkflowWait>  $waits
     */
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public array $waits,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
