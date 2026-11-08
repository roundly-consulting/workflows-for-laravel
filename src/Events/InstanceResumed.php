<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A waiting step resumed (an addition to the brief's events).
 */
final readonly class InstanceResumed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public SignalKind $signal,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
