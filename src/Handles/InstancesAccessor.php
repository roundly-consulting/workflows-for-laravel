<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Workflows\Actions\Instances\CancelInstanceAction;
use RoundlyConsulting\Workflows\Actions\Instances\RetryInstanceAction;
use RoundlyConsulting\Workflows\DataTransferObjects\PruneReport;
use RoundlyConsulting\Workflows\DataTransferObjects\WakeReport;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Instances: find, retry a failed one, cancel, read the step log, wake and prune. Retry and
 * cancel run inside the instance's owner context; host controllers authorize them first.
 */
final readonly class InstancesAccessor
{
    public function __construct(
        private WorkflowsManager $manager,
    ) {}

    /**
     * By id or ulid.
     */
    public function find(int|string $id): ?WorkflowInstance
    {
        $model = WorkflowModels::instance();
        $instance = is_string($id) && strlen($id) === 26 && ! ctype_digit($id)
            ? $model::query()->where('ulid', strtoupper($id))->first()
            : $model::query()->find($id);

        return $instance instanceof WorkflowInstance ? $instance : null;
    }

    public function retry(WorkflowInstance $instance): ?WorkflowInstance
    {
        return $this->manager->perform(
            WorkflowOperation::Retry,
            RetryInstanceAction::class,
            static fn (RetryInstanceAction $action): WorkflowInstance => $action->execute($instance),
            ['instance' => $instance],
        );
    }

    public function cancel(WorkflowInstance $instance, ?string $reason = null): ?WorkflowInstance
    {
        return $this->manager->perform(
            WorkflowOperation::Cancel,
            CancelInstanceAction::class,
            static fn (CancelInstanceAction $action): WorkflowInstance => $action->execute($instance, $reason),
            ['instance' => $instance, 'reason' => $reason],
        );
    }

    /**
     * The step log: every attempt, in order.
     *
     * @return Collection<int, StepRun>
     */
    public function steps(WorkflowInstance $instance): Collection
    {
        $model = WorkflowModels::stepRun();

        return $model::query()->where('instance_id', $instance->getKey())->orderBy('id')->get();
    }

    public function wake(): WakeReport
    {
        return $this->manager->wake();
    }

    public function prune(): PruneReport
    {
        return $this->manager->prune();
    }
}
