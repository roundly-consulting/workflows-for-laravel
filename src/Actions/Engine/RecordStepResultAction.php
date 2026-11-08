<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Engine;

use Closure;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Events\InstanceWaiting;
use RoundlyConsulting\Workflows\Events\StepCompleted;
use RoundlyConsulting\Workflows\Events\StepFailed;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Engine\Advancer;
use RoundlyConsulting\Workflows\Support\Engine\CancelHook;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Support\Engine\LockedRun;
use RoundlyConsulting\Workflows\Support\Engine\Output;
use RoundlyConsulting\Workflows\Support\Engine\RetryPolicies;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\Engine\Waits;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * TX-record: locks the instance, then the step run; the claim token must still match and the
 * step must still be running, else the result is discarded (a lost lease, or a cancel). Then it
 * completes the step and advances, records the wait, or fails the attempt (a retry with the
 * same idempotency key, or the instance fails).
 *
 * @internal
 */
final readonly class RecordStepResultAction
{
    public function __construct(
        private Advancer $advancer,
        private EngineQueue $queue,
        private StepCatalogue $catalogue,
        private LoggerInterface $logger,
        private CancelHook $cancelHook,
    ) {}

    /**
     * @param  (Closure(): (StepResult|StepFailure))|null  $transactional
     */
    public function execute(int|string $instanceId, int|string $runId, string $token, StepResult|StepFailure|null $result, ?Closure $transactional = null, ?Signal $signal = null): void
    {
        $after = [];
        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        $connection->transaction(function () use ($instanceId, $runId, $token, $result, $transactional, $signal, &$after): void {
            $lock = $this->lock($instanceId, $runId);
            $instance = $lock->instance;
            $run = $lock->run;

            if ($instance === null || $run === null) {
                return;
            }

            if ($run->status !== StepRunStatus::Running || $run->claim_token !== $token) {
                $this->logger->info('workflows: stale step result discarded', ['step_run' => Keys::of($run), 'status' => $run->status->value]);

                return;
            }

            if (! $instance->status->isActive()) {
                $run->status = StepRunStatus::Cancelled;
                $run->finished_at = Clock::now();

                // The instance was cancelled while this attempt ran: the cancel saw none of what
                // the handler did (the round it opened). Keep its output and clean up after it —
                // also after a failure, whose effects the hook finds through recall().
                if ($result instanceof StepResult && $result->output !== [] && Output::valid($result->output)) {
                    $run->output = array_replace($run->output ?? [], $result->output);
                }

                $run->save();
                $this->cancelHook->run($instance, $run);

                return;
            }

            if ($transactional !== null) {
                $result = $transactional();
            }

            $this->apply($instance, $run, $result ?? StepFailure::permanent(FailureCode::NoResult, Messages::get('failures.no_result')), $signal, $after);
        });

        foreach ($after as $callback) {
            $callback();
        }
    }

    /**
     * Fail a running attempt whose lease has expired (a crashed worker, a worker timeout): a
     * compare-and-set on status and lease, so two reclaims — or a reclaim racing the job's
     * failed() — fail it once, and a live handler is never touched.
     */
    public function failExpired(int|string $runId, StepFailure $failure): bool
    {
        $runModel = WorkflowModels::stepRun();
        $run = $runModel::query()->find($runId);

        if (! $run instanceof StepRun) {
            return false;
        }

        $after = [];
        $failed = false;
        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        $connection->transaction(function () use ($run, $failure, &$after, &$failed): void {
            $lock = $this->lock($run->instance_id, Keys::of($run));
            $instance = $lock->instance;
            $locked = $lock->run;

            if ($instance === null || $locked === null || $locked->status !== StepRunStatus::Running
                || $locked->lease_until === null || $locked->lease_until->greaterThanOrEqualTo(Clock::now())) {
                return;
            }

            if (! $instance->status->isActive()) {
                $locked->status = StepRunStatus::Cancelled;
                $locked->save();

                // Its worker died after the cancel: clean up whatever it did (through recall()).
                $this->cancelHook->run($instance, $locked);

                return;
            }

            $failed = true;
            $this->apply($instance, $locked, $failure, null, $after);
        });

        foreach ($after as $callback) {
            $callback();
        }

        return $failed;
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function apply(WorkflowInstance $instance, StepRun $run, StepResult|StepFailure $result, ?Signal $signal, array &$after): void
    {
        $version = $instance->version;
        $graph = $version instanceof WorkflowVersion ? $version->parsedGraph() : null;
        $node = $graph?->node($run->node_id);

        if (! $version instanceof WorkflowVersion || $graph === null || $node === null) {
            // The version exists but has no such node: keep the attempt's own failure
            // (unknown_step_type), never claim the version is gone.
            $failure = $version instanceof WorkflowVersion && $result instanceof StepFailure
                ? $result
                : StepFailure::permanent(FailureCode::VersionMissing, Messages::get('failures.version_missing'));

            $this->failRun($run, $failure);
            $this->advancer->fail($instance, $failure->code, $failure->message, $run->node_id, $after);

            return;
        }

        if ($result instanceof StepResult && $result->isFail()) {
            $result = StepFailure::permanent((string) $result->failureCode, (string) $result->failureMessage);
        }

        if ($result instanceof StepResult && ! Output::valid($result->output)) {
            $result = StepFailure::permanent(FailureCode::InvalidOutput, Messages::get('failures.invalid_output'));
        }

        match (true) {
            $result instanceof StepFailure => $this->failure($instance, $run, $graph, $node, $result, $after),
            $result->isWait() => $this->wait($instance, $run, $version, $graph, $node, $result, $signal, $after),
            default => $this->complete($instance, $run, $graph, $node, $result, $after),
        };
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function complete(WorkflowInstance $instance, StepRun $run, Graph $graph, Node $node, StepResult $result, array &$after): void
    {
        $run->status = StepRunStatus::Completed;
        $run->outcome = Str::limit($result->outcome, 32, '');
        $run->output = array_replace($run->output ?? [], $result->output);
        $run->finished_at = Clock::now();
        $run->save();

        Advancer::retireWaitsAndEscalations($instance, Keys::of($run));
        Advancer::retireEscalations($run);

        Events::dispatch(new StepCompleted($instance, $run, $result->outcome, $instance, Runtime::actor($instance)));

        if ($node->type === 'end') {
            $this->advancer->end($instance, $run, $node, $after);

            return;
        }

        $edge = $graph->edgeFor($node->id, $result->outcome);

        if ($edge === null) {
            $this->advancer->fail($instance, FailureCode::MissingOutcomeEdge, Messages::get('failures.missing_outcome_edge', ['node' => $node->id, 'outcome' => $result->outcome]), $node->id, $after);

            return;
        }

        $this->advancer->advance($instance, $graph, $edge->to, $after);
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function wait(WorkflowInstance $instance, StepRun $run, WorkflowVersion $version, Graph $graph, Node $node, StepResult $result, ?Signal $signal, array &$after): void
    {
        $run->status = StepRunStatus::Waiting;
        $run->output = array_replace($run->output ?? [], $result->output);
        $run->save();

        $instance->status = InstanceStatus::Waiting;
        $instance->current_node_id = $node->id;
        $instance->lock_version++;
        $instance->save();

        $existing = Waits::pending($run);
        $runId = Keys::of($run);

        // A signal that claimed a wait while this attempt ran found the step busy and left the wait
        // claimed: deliver it now that the step waits again.
        foreach ($existing as $claimed) {
            if ($claimed->status === WaitStatus::Claimed) {
                $waitId = Keys::of($claimed);
                $kind = Waits::signalKind($claimed->kind);
                $after[] = fn () => $this->queue->resume($runId, $waitId, $kind);
            }
        }

        // A probe that finds the source still pending keeps the waits it already has.
        if ($signal?->kind === SignalKind::Probe && $existing !== []) {
            return;
        }

        $owner = Runtime::owner($instance);
        $timezone = Runtime::timezone($version);
        $calendar = Waits::forGraph($graph);
        $spec = $result->wait;

        $waits = $spec === null ? [] : Waits::create($instance, $run, $spec, $timezone, $calendar, $owner);

        $type = $this->catalogue->find($node->type);

        if ($type?->supportsEscalations() === true) {
            Waits::escalate($instance, $run, $node, $timezone, $calendar, $owner);
        }

        Events::dispatch(new InstanceWaiting($instance, $run, $waits, $instance, Runtime::actor($instance)));

        if ($spec?->probe === true) {
            $run->probe_pushed_at = Clock::now();
            $run->save();

            $after[] = fn () => $this->queue->resume($runId, null, SignalKind::Probe);
        }
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function failure(WorkflowInstance $instance, StepRun $run, Graph $graph, Node $node, StepFailure $failure, array &$after): void
    {
        $this->failRun($run, $failure);
        Advancer::retireWaitsAndEscalations($instance, Keys::of($run));

        $policy = RetryPolicies::resolve($node, $graph, $this->catalogue->find($node->type));

        if ($failure->retryable && $run->attempt < $policy->maxAttempts) {
            $due = Clock::now()->addSeconds(RetryPolicies::delay($policy, $run->attempt + 1));

            if ($failure->retryAfter !== null && $failure->retryAfter->greaterThan($due)) {
                $due = $failure->retryAfter;
            }

            $next = $this->advancer->createRun($instance, $node, $run->attempt + 1, $run->visit, $run->idempotency_key);
            $next->scheduled_for = $due;

            // A failed resume is retried as a resume: the next attempt gets the same signal (an
            // event's payload, a decision) and the state the step waited with, never a fresh handle().
            if ($run->resume_signal !== null) {
                $next->resume_signal = $run->resume_signal;
                $next->input = $run->input;
                $next->output = $run->output;
            }

            $next->save();

            Waits::retry($instance, $next, $due);

            $instance->status = InstanceStatus::Running;
            $instance->lock_version++;
            $instance->save();

            Events::dispatch(new StepFailed($instance, $run, $failure->code, true, $instance, Runtime::actor($instance)));

            return;
        }

        Advancer::retireEscalations($run);

        Events::dispatch(new StepFailed($instance, $run, $failure->code, false, $instance, Runtime::actor($instance)));

        $this->advancer->fail($instance, $failure->code, $failure->message, $node->id, $after);
    }

    private function failRun(StepRun $run, StepFailure $failure): void
    {
        $run->status = StepRunStatus::Failed;
        $run->error_code = Str::limit($failure->code, 64, '');
        $run->error_message = $failure->message;
        $run->error_retryable = $failure->retryable;
        $run->finished_at = Clock::now();
        $run->save();
    }

    /**
     * Instance first, then the step run: the global lock order.
     */
    private function lock(int|string $instanceId, int|string $runId): LockedRun
    {
        $instanceModel = WorkflowModels::instance();
        $runModel = WorkflowModels::stepRun();

        $instance = $instanceModel::query()->lockForUpdate()->find($instanceId);
        $run = $runModel::query()->lockForUpdate()->find($runId);

        return new LockedRun(
            $instance instanceof WorkflowInstance ? $instance : null,
            $run instanceof StepRun ? $run : null,
        );
    }
}
