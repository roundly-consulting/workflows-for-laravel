<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use Throwable;

/**
 * Runs one step attempt. Holds only the step run id; `tries = 1` because the engine owns
 * retries. Dispatched after the transaction that created the step run commits.
 */
final class RunStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly int|string $stepRunId,
    ) {
        $this->timeout = max(30, (int) WorkflowsConfig::lease()->totalSeconds - 30);
        $this->onConnection(WorkflowsConfig::queueConnection());
        $this->onQueue(WorkflowsConfig::queueName());
        $this->afterCommit();
    }

    public function handle(RunStepAction $action): void
    {
        $action->execute($this->stepRunId);
    }

    /**
     * A worker timeout fails the attempt only through a compare-and-set on an expired lease, so
     * a redelivery of a still-running job never schedules a retry next to the live handler.
     */
    public function failed(?Throwable $exception = null): void
    {
        app(RecordStepResultAction::class)->failExpired(
            $this->stepRunId,
            StepFailure::transient(FailureCode::WorkerTimeout, Messages::get('failures.worker_timeout')),
        );
    }
}
