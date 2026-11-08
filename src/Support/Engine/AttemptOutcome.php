<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;
use RoundlyConsulting\Workflows\Steps\StepResult;

/**
 * What one attempt produced outside the lock: a result or failure, or — for a transactional
 * built-in — the closure the recorder runs inside its transaction.
 *
 * @internal
 */
final readonly class AttemptOutcome
{
    /**
     * @param  (Closure(): (StepResult|StepFailure))|null  $transactional
     */
    public function __construct(
        public StepResult|StepFailure|null $result,
        public ?Closure $transactional = null,
    ) {}
}
