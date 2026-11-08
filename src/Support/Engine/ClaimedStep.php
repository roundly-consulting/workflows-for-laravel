<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A step run claimed under the instance lock: the instance, the run, its claim token, and the
 * signal that resumed it (none for a first run).
 *
 * @internal
 */
final readonly class ClaimedStep
{
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $run,
        public string $token,
        public ?Signal $signal = null,
    ) {}
}
