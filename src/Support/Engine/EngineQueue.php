<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use RoundlyConsulting\Workflows\Actions\Engine\FireEscalationAction;
use RoundlyConsulting\Workflows\Actions\Engine\ResumeStepAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Jobs\FireEscalationJob;
use RoundlyConsulting\Workflows\Jobs\ResumeStepJob;
use RoundlyConsulting\Workflows\Jobs\RunStepJob;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Pushes engine work: a queued job per step (after the surrounding transaction commits), or —
 * with `queue.enabled = off` — the same action on the iterative SyncRunner.
 *
 * @internal
 */
final readonly class EngineQueue
{
    public function __construct(
        private Dispatcher $bus,
        private SyncRunner $runner,
    ) {}

    public function runStep(StepRun|int|string $run): void
    {
        $id = $run instanceof StepRun ? Keys::of($run) : $run;

        $model = WorkflowModels::stepRun();
        $model::query()->whereKey($id)->update(['dispatched_at' => Clock::format(Clock::now())]);

        $this->push(
            new RunStepJob($id),
            static function () use ($id): void {
                app(RunStepAction::class)->execute($id);
            },
        );
    }

    public function resume(int|string $stepRunId, int|string|null $waitId, SignalKind $kind): void
    {
        $this->push(
            new ResumeStepJob($stepRunId, $waitId, $kind->value),
            static function () use ($stepRunId, $waitId, $kind): void {
                app(ResumeStepAction::class)->execute($stepRunId, $waitId, $kind);
            },
        );
    }

    public function escalate(int|string $escalationId): void
    {
        $this->push(
            new FireEscalationJob($escalationId),
            static function () use ($escalationId): void {
                app(FireEscalationAction::class)->execute($escalationId);
            },
        );
    }

    /**
     * @param  Closure(): mixed  $inline
     */
    private function push(RunStepJob|ResumeStepJob|FireEscalationJob $job, Closure $inline): void
    {
        if (WorkflowsConfig::queueEnabled()) {
            $this->bus->dispatch($job);

            return;
        }

        $this->runner->push($inline);

        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit(fn () => $this->runner->drain());

            return;
        }

        $this->runner->drain();
    }
}
