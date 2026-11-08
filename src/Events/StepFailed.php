<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A step attempt failed; `willRetry` says whether another attempt is scheduled.
 */
final readonly class StepFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public string $code,
        public bool $willRetry,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
