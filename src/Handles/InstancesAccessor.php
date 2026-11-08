<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Workflows\Actions\Instances\CancelInstanceAction;
use RoundlyConsulting\Workflows\Actions\Instances\RetryInstanceAction;
use RoundlyConsulting\Workflows\DataTransferObjects\PruneReport;
use RoundlyConsulting\Workflows\DataTransferObjects\WakeReport;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Owners;
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

        if (is_string($id) && Str::isUlid($id)) {
            $instance = $model::query()->where('ulid', strtoupper($id))->first();

            if ($instance instanceof WorkflowInstance) {
                return $instance;
            }
        }

        // Never hand the database a value its key column cannot hold (PostgreSQL refuses it).
        $valid = match ((new $model)->configuredKeyType()) {
            KeyType::BigInt => is_int($id) || ctype_digit($id),
            KeyType::Uuid => is_string($id) && Str::isUuid($id),
            KeyType::Ulid => is_string($id) && Str::isUlid($id),
        };

        $instance = $valid ? $model::query()->find($id) : null;

        return $instance instanceof WorkflowInstance ? $instance : null;
    }

    public function retry(WorkflowInstance $instance): WorkflowInstance
    {
        return $this->manager->perform(
            WorkflowOperation::Retry,
            RetryInstanceAction::class,
            static fn (RetryInstanceAction $action): WorkflowInstance => $action->execute($instance),
            static fn (): WorkflowInstance => $instance,
            ['instance' => $instance],
        );
    }

    public function cancel(WorkflowInstance $instance, ?string $reason = null): WorkflowInstance
    {
        return $this->manager->perform(
            WorkflowOperation::Cancel,
            CancelInstanceAction::class,
            static fn (CancelInstanceAction $action): WorkflowInstance => $action->execute($instance, $reason),
            static fn (): WorkflowInstance => $instance,
            ['instance' => $instance, 'reason' => $reason],
        );
    }

    /**
     * The newest instances, for operators: by status, by definition key (of any owner, unless an
     * owner is given), by owner.
     *
     * @return Collection<int, WorkflowInstance>
     */
    public function recent(?InstanceStatus $status = null, ?string $definition = null, ?Model $owner = null, int $limit = 25): Collection
    {
        $model = WorkflowModels::instance();
        $definitionModel = WorkflowModels::definition();

        return $model::query()
            ->when($status !== null, static fn (Builder $query) => $query->where('status', $status?->value))
            ->when($definition !== null, static fn (Builder $query) => $query->whereIn('definition_id', $definitionModel::query()
                ->where('key', $definition)
                ->when($owner !== null, static fn (Builder $definitions) => $definitions->where('owner_scope', Owners::scope($owner)))
                ->select('id')))
            ->when($owner !== null, static fn (Builder $query) => $query->where('owner_type', $owner?->getMorphClass())->where('owner_id', $owner?->getKey()))
            ->with(['definition', 'version'])
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Load what an instance detail view shows — the step log, the pending waits and the pending
     * escalations — once, for InstanceResource.
     */
    public function detail(WorkflowInstance $instance): WorkflowInstance
    {
        return $instance->loadMissing([
            'definition',
            'version',
            'stepRuns' => static fn (Relation $query) => $query->orderBy('id'),
            'waits' => static fn (Relation $query) => $query->where('status', WaitStatus::Pending->value)->orderBy('due_at'),
            'escalations' => static fn (Relation $query) => $query->where('status', EscalationStatus::Pending->value)->orderBy('due_at'),
        ]);
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
