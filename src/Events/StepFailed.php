<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A step attempt failed; `willRetry` says whether another attempt is scheduled. `details` is the
 * step's structured failure details (secret-looking keys redacted, oversized ones truncated to
 * `{"truncated": true, "bytes": n}`), null when it gave none.
 */
final readonly class StepFailed implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public function __construct(
        public WorkflowInstance $instance,
        public StepRun $stepRun,
        public string $code,
        public bool $willRetry,
        public WorkflowInstance $actor,
        public Actor $runAs,
        public ?array $details = null,
    ) {}
}
