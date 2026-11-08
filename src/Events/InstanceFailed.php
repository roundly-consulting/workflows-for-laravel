<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An instance failed: a step failed for good, a limit was hit, or an end node failed it.
 */
final readonly class InstanceFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public WorkflowInstance $instance,
        public string $code,
        public string $message,
        public ?string $node,
        public WorkflowInstance $actor,
        public Actor $runAs,
    ) {}
}
