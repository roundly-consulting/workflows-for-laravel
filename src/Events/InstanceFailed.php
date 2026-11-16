<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * An instance failed: a step failed for good, a limit was hit, or an end node failed it.
 * `details` is the failing step's structured details (redacted and capped like the step's), null
 * when it gave none or the instance failed for another reason.
 */
final readonly class InstanceFailed implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public function __construct(
        public WorkflowInstance $instance,
        public string $code,
        public string $message,
        public ?string $node,
        public WorkflowInstance $actor,
        public Actor $runAs,
        public ?array $details = null,
    ) {}
}
