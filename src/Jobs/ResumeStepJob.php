<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RoundlyConsulting\Workflows\Actions\Engine\ResumeStepAction;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Resumes a waiting step when one of its waits fired (or probes it once right after it started
 * waiting). Holds only ids; the owner context is restored before anything is read.
 */
final class ResumeStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly int|string $stepRunId,
        public readonly int|string|null $waitId,
        public readonly string $kind,
    ) {
        $this->timeout = max(30, (int) WorkflowsConfig::lease()->totalSeconds - 30);
        $this->onConnection(WorkflowsConfig::queueConnection());
        $this->onQueue(WorkflowsConfig::queueName());
        $this->afterCommit();
    }

    public function handle(ResumeStepAction $action): void
    {
        $action->execute($this->stepRunId, $this->waitId, SignalKind::from($this->kind));
    }
}
