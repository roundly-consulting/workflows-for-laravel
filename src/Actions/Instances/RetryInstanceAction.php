<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Events\InstanceRetried;
use RoundlyConsulting\Workflows\Exceptions\InstanceNotRetryableException;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Engine\Advancer;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * Retries a failed instance from the step that failed: a new attempt of the same visit, with
 * the same idempotency key (so an effect recorded with `once()` is not repeated). A step whose
 * `resume()` failed is resumed again with the signal it received; one whose `handle()` failed runs
 * `handle()` again. The attempt cap does not apply; on success the instance resumes from that node.
 */
final readonly class RetryInstanceAction
{
    public function __construct(
        private RestoresOwnerContext $context,
        private Advancer $advancer,
        private EngineQueue $queue,
    ) {}

    public function execute(WorkflowInstance $instance): WorkflowInstance
    {
        return $this->context->run(Runtime::owner($instance), fn (): WorkflowInstance => $this->retry($instance));
    }

    private function retry(WorkflowInstance $instance): WorkflowInstance
    {
        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        $run = $connection->transaction(function () use ($instance, $model): StepRun {
            $locked = $model::query()->whereKey($instance->getKey())->lockForUpdate()->firstOrFail();

            $runModel = WorkflowModels::stepRun();
            $last = $runModel::query()->where('instance_id', $locked->getKey())->orderByDesc('id')->first();

            if ($locked->status !== InstanceStatus::Failed) {
                throw InstanceNotRetryableException::forStatus($locked->status->value);
            }

            if (! $last instanceof StepRun || $last->status !== StepRunStatus::Failed) {
                throw InstanceNotRetryableException::noFailedStep();
            }

            $graph = $locked->version?->parsedGraph();
            $node = $graph?->node($last->node_id);

            if ($node === null) {
                throw InstanceNotRetryableException::forStatus($locked->status->value);
            }

            $next = $this->advancer->createRun($locked, $node, $last->attempt + 1, $last->visit, $last->idempotency_key);

            // A failed resume is retried as a resume, exactly like the automatic retry: the signal
            // it received (the event will not come again) and the state the step waited with.
            if ($last->resume_signal !== null) {
                $next->resume_signal = $last->resume_signal;
                $next->input = $last->input;
                $next->output = $last->output;
                $next->save();
            }

            $locked->status = InstanceStatus::Running;
            $locked->failure_code = null;
            $locked->failure_message = null;
            $locked->failed_node_id = null;
            $locked->ended_at = null;
            $locked->current_node_id = $node->id;
            $locked->lock_version++;
            $locked->save();

            Events::dispatch(new InstanceRetried($locked, $next, $locked, Runtime::actor($locked)));

            return $next;
        });

        $this->queue->runStep($run);

        return $instance->refresh();
    }
}
