<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A step attempt was claimed and is about to run.
 */
final readonly class StepStarted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
