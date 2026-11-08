<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A step attempt completed with an outcome.
 */
final readonly class StepCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public string $outcome,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
