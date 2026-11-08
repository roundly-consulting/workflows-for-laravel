<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An instance and one of its step runs, locked in the global order (instance first); either may
 * be gone.
 *
 * @internal
 */
final readonly class LockedRun
{
    public function __construct(
        public ?WorkflowInstance $instance,
        public ?StepRun $run,
    ) {}
}
