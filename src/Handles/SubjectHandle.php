<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Instances\TouchSubjectAction;
use RoundlyConsulting\Workflows\Builders\PendingStart;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitResult;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Everything about one subject: starts and emits about it, its run history, its running
 * instances, cancelling them, and `touch()` (re-evaluate its condition waits). Only this
 * subject's instances are ever returned.
 */
final readonly class SubjectHandle
{
    public function __construct(
        private WorkflowsManager $manager,
        private Model $subject,
    ) {}

    /**
     * A manual start of the subject owner's (or an app-wide) definition.
     */
    public function start(string $key, ?Model $owner = null): PendingStart
    {
        return $this->manager->start($key, $this->subject, $owner ?? app(Owners::class)->ownerOf($this->subject));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $event, array $payload = [], ?string $idempotencyKey = null, ?Model $user = null): EmitResult
    {
        return $this->manager->emit($event, $this->subject, $payload, $idempotencyKey, $user);
    }

    /**
     * Run history, newest first.
     *
     * @return CursorPaginator<int, WorkflowInstance>
     */
    public function instances(int $perPage = 25): CursorPaginator
    {
        return $this->query()->with(['owner', 'starter'])->orderByDesc('id')->cursorPaginate($perPage);
    }

    /**
     * @return Collection<int, WorkflowInstance>
     */
    public function running(): Collection
    {
        return $this->query()->whereIn('status', [InstanceStatus::Running->value, InstanceStatus::Waiting->value])->orderBy('id')->get();
    }

    /**
     * Cancel every running or waiting instance of the subject.
     */
    public function cancelAll(?string $reason = null): int
    {
        $cancelled = 0;

        foreach ($this->running() as $instance) {
            $this->manager->instances()->cancel($instance, $reason);
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * The subject changed: re-evaluate its pending condition waits (in their owner context).
     */
    public function touch(): int
    {
        $subject = $this->subject;

        return $this->manager->perform(
            WorkflowOperation::Touch,
            TouchSubjectAction::class,
            static fn (TouchSubjectAction $action): int => $action->execute($subject),
            ['subject' => $subject],
        ) ?? 0;
    }

    /**
     * @return Builder<WorkflowInstance>
     */
    private function query(): Builder
    {
        $model = WorkflowModels::instance();

        return $model::query()
            ->where('subject_type', $this->subject->getMorphClass())
            ->where('subject_id', $this->subject->getKey());
    }
}
