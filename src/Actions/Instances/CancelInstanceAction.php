<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Closure;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Events\InstanceCancelled;
use RoundlyConsulting\Workflows\Exceptions\InstanceNotCancellableException;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Engine\Advancer;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Engine\Signals;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use Throwable;

/**
 * Cancels a running or waiting instance: its waits and escalations are retired first, then the
 * waiting step's `cancel()` hook runs (an approval closes its round, whose resolution then finds
 * nothing to resume), and children it waits on are cancelled too (parent → child lock order).
 * A handler that finishes afterwards sees its result discarded.
 */
final readonly class CancelInstanceAction
{
    public function __construct(
        private RestoresOwnerContext $context,
        private StepCatalogue $catalogue,
        private Signals $signals,
    ) {}

    public function execute(WorkflowInstance $instance, ?string $reason = null): WorkflowInstance
    {
        return $this->context->run(Runtime::owner($instance), function () use ($instance, $reason): WorkflowInstance {
            $model = WorkflowModels::instance();
            $connection = (new $model)->getConnection();
            $after = [];

            $connection->transaction(function () use ($instance, $reason, $model, &$after): void {
                $locked = $model::query()->whereKey($instance->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->status->isActive()) {
                    throw InstanceNotCancellableException::forStatus($locked->status->value);
                }

                $this->cancel($locked, $reason, $after);
            });

            foreach ($after as $callback) {
                $callback();
            }

            return $instance->refresh();
        });
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function cancel(WorkflowInstance $instance, ?string $reason, array &$after): void
    {
        Advancer::retireWaitsAndEscalations($instance);

        $runModel = WorkflowModels::stepRun();
        $runs = $runModel::query()
            ->where('instance_id', $instance->getKey())
            ->whereIn('status', [StepRunStatus::Waiting->value, StepRunStatus::Queued->value])
            ->get();

        foreach ($runs as $run) {
            if ($run->status === StepRunStatus::Waiting) {
                $this->hook($instance, $run);
            }

            $run->status = StepRunStatus::Cancelled;
            $run->finished_at = Clock::now();
            $run->save();
        }

        $instance->status = InstanceStatus::Cancelled;
        $instance->ended_at = Clock::now();
        $instance->lock_version++;
        $instance->save();

        Events::dispatch(new InstanceCancelled($instance, $reason, $instance, Runtime::actor($instance)));

        $instanceModel = WorkflowModels::instance();
        $children = $instanceModel::query()
            ->where('parent_instance_id', $instance->getKey())
            ->whereIn('status', [InstanceStatus::Running->value, InstanceStatus::Waiting->value])
            ->lockForUpdate()
            ->get();

        foreach ($children as $child) {
            $this->cancel($child, $reason, $after);
        }

        if ($instance->parent_instance_id !== null) {
            $key = '@instance.'.strtolower($instance->ulid).'.ended';
            $after[] = fn () => $this->signals->send($key, null, ['status' => 'cancelled'], null, internal: true);
        }
    }

    private function hook(WorkflowInstance $instance, StepRun $run): void
    {
        $version = $instance->version;
        $graph = $version?->parsedGraph();
        $node = $graph?->node($run->node_id);
        $type = $node === null ? null : $this->catalogue->find($node->type);

        if ($version === null || $graph === null || $node === null || $type === null) {
            return;
        }

        try {
            $subject = $instance->subject_type === null ? null : Runtime::subject($instance);
            $type->cancel(Runtime::context($instance, $run, $version, $graph, $node, $subject, Runtime::owner($instance)));
        } catch (Throwable) {
            // A failing clean-up never blocks the cancel.
        }
    }
}
