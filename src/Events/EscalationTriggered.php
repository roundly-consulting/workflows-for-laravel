<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An escalation level fired its actions.
 */
final readonly class EscalationTriggered implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public int $level,
        public array $actions,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
