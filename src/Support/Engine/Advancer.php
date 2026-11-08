<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Events\InstanceCompleted;
use RoundlyConsulting\Workflows\Events\InstanceFailed;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Moves a locked instance along its graph: the next step run (visit-numbered, with its
 * idempotency key), the step limit, an end node completing (or failing) the instance inline, and
 * failing an instance. Callers hold the instance row lock; work that must wait for the commit
 * (dispatches, cross-instance signals) is collected in `$after`.
 *
 * @internal
 */
final readonly class Advancer
{
    public function __construct(
        private EngineQueue $queue,
    ) {}

    /**
     * @param  list<Closure(): mixed>  $after
     */
    public function advance(WorkflowInstance $instance, Graph $graph, string $target, array &$after): void
    {
        $node = $graph->node($target);

        if ($node === null) {
            $this->fail($instance, 'missing_edge', Messages::get('failures.missing_edge', ['node' => $target]), $target, $after);

            return;
        }

        $limit = self::maxSteps($graph);

        if ($instance->steps_count + 1 > $limit) {
            $this->fail($instance, 'step_limit_exceeded', Messages::get('failures.step_limit_exceeded', ['limit' => $limit]), $target, $after);

            return;
        }

        $run = $this->createRun($instance, $node);

        $instance->current_node_id = $target;
        $instance->steps_count++;
        $instance->status = InstanceStatus::Running;
        $instance->lock_version++;
        $instance->save();

        if ($node->type === 'end') {
            $this->end($instance, $run, $node, $after);

            return;
        }

        $after[] = fn () => $this->queue->runStep($run);
    }

    public function createRun(WorkflowInstance $instance, Node $node, int $attempt = 1, ?int $visit = null, ?string $key = null): StepRun
    {
        $model = WorkflowModels::stepRun();

        $visit ??= $model::query()
            ->where('instance_id', Keys::of($instance))
            ->where('node_id', $node->id)
            ->where('attempt', 1)
            ->count() + 1;

        $run = new $model;
        $run->instance_id = Keys::of($instance);
        $run->node_id = $node->id;
        $run->node_type = $node->type;
        $run->visit = $visit;
        $run->attempt = $attempt;
        $run->status = StepRunStatus::Queued;
        $run->idempotency_key = $key ?? self::key($instance, $node->id, $visit);
        $run->save();

        return $run;
    }

    public static function key(WorkflowInstance $instance, string $node, int $visit): string
    {
        return Str::limit('wf:'.strtolower($instance->ulid).':'.$node.':'.$visit, 100, '');
    }

    /**
     * Complete an end node and the instance (or fail it, for `{"status": "failed"}`).
     *
     * @param  list<Closure(): mixed>  $after
     */
    public function end(WorkflowInstance $instance, StepRun $run, Node $node, array &$after): void
    {
        $now = Clock::now();

        $run->status = StepRunStatus::Completed;
        $run->outcome = 'end';
        $run->started_at = $now;
        $run->finished_at = $now;
        $run->save();

        if (($node->config['status'] ?? 'completed') === 'failed') {
            $reason = $node->config['reason'] ?? null;
            $this->fail($instance, 'ended_by_graph', is_string($reason) && $reason !== '' ? $reason : Messages::get('failures.ended_by_graph'), $node->id, $after);

            return;
        }

        $instance->status = InstanceStatus::Completed;
        $instance->current_node_id = $node->id;
        $instance->ended_at = $now;
        $instance->lock_version++;
        $instance->save();

        Events::dispatch(new InstanceCompleted($instance, $instance, Runtime::actor($instance)));

        $this->signalParent($instance, $after);
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    public function fail(WorkflowInstance $instance, string $code, string $message, ?string $node, array &$after): void
    {
        $instance->status = InstanceStatus::Failed;
        $instance->failure_code = Str::limit($code, 64, '');
        $instance->failure_message = $message;
        $instance->failed_node_id = $node;
        $instance->ended_at = Clock::now();
        $instance->lock_version++;
        $instance->save();

        self::retireWaitsAndEscalations($instance);

        Events::dispatch(new InstanceFailed($instance, $code, $message, $node, $instance, Runtime::actor($instance)));

        $this->signalParent($instance, $after);
    }

    public static function retireWaitsAndEscalations(WorkflowInstance $instance, int|string|null $runId = null): void
    {
        $now = Clock::format(Clock::now());
        $wait = WorkflowModels::wait();
        $escalation = WorkflowModels::escalation();

        $wait::query()
            ->where('instance_id', Keys::of($instance))
            ->when($runId !== null, static fn ($query) => $query->where('step_run_id', $runId))
            ->whereIn('status', [WaitStatus::Pending->value, WaitStatus::Claimed->value])
            ->update(['status' => WaitStatus::Cancelled->value, 'updated_at' => $now]);

        if ($runId === null) {
            $escalation::query()
                ->where('instance_id', Keys::of($instance))
                ->whereIn('status', [EscalationStatus::Pending->value, EscalationStatus::Claimed->value])
                ->update(['status' => EscalationStatus::Cancelled->value, 'cancelled_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Cancel the pending escalations of one visit (every attempt of it).
     */
    public static function retireEscalations(StepRun $run): void
    {
        $now = Clock::format(Clock::now());
        $stepRun = WorkflowModels::stepRun();
        $escalation = WorkflowModels::escalation();

        $runs = $stepRun::query()
            ->where('instance_id', $run->instance_id)
            ->where('node_id', $run->node_id)
            ->where('visit', $run->visit)
            ->pluck('id')
            ->all();

        $escalation::query()
            ->whereIn('step_run_id', $runs)
            ->whereIn('status', [EscalationStatus::Pending->value, EscalationStatus::Claimed->value])
            ->update(['status' => EscalationStatus::Cancelled->value, 'cancelled_at' => $now, 'updated_at' => $now]);
    }

    public static function maxSteps(Graph $graph): int
    {
        $limit = WorkflowsConfig::maxSteps();
        $limits = $graph->setting('limits');
        $lower = is_array($limits) ? ($limits['max_steps'] ?? null) : null;

        return is_int($lower) && $lower > 0 ? min($limit, $lower) : $limit;
    }

    /**
     * A child that ended tells its waiting parent — always after the commit, so a child's
     * transaction never locks its parent (child → parent would deadlock against the cascade).
     *
     * @param  list<Closure(): mixed>  $after
     */
    private function signalParent(WorkflowInstance $instance, array &$after): void
    {
        if ($instance->parent_instance_id === null) {
            return;
        }

        $key = '@instance.'.strtolower($instance->ulid).'.ended';
        $status = $instance->status->value;

        $after[] = static fn () => app(Signals::class)->send($key, null, ['status' => $status], null, internal: true);
    }
}
