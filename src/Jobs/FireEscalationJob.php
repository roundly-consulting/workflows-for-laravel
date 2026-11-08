<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RoundlyConsulting\Workflows\Actions\Engine\FireEscalationAction;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Fires one claimed escalation level inside its instance's owner context.
 */
final class FireEscalationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly int|string $escalationId,
    ) {
        $this->timeout = max(30, (int) WorkflowsConfig::lease()->totalSeconds - 30);
        $this->onConnection(WorkflowsConfig::queueConnection());
        $this->onQueue(WorkflowsConfig::queueName());
        $this->afterCommit();
    }

    public function handle(FireEscalationAction $action): void
    {
        $action->execute($this->escalationId);
    }
}
