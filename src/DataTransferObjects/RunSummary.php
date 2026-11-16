<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One instance in an owner export: what ran, on what, when and how it ended, and each step's
 * outcome. Never a step's input or output, the trigger payload, a failure message, failure
 * details or a class name: references are `{type, id}` with a registry key or a basename as the type.
 */
final readonly class RunSummary
{
    /**
     * @param  array{type: string, key: string|null}  $trigger
     * @param  array{type: string, id: int|string}|null  $subject
     * @param  array{type: string, id: int|string}|null  $startedBy
     * @param  array{code: string, node: string|null}|null  $failure
     * @param  list<array{node: string, type: string, visit: int, attempt: int, status: string, outcome: string|null, error: string|null, started_at: string|null, finished_at: string|null}>  $steps
     */
    public function __construct(
        public string $id,
        public ?string $definition,
        public ?int $version,
        public string $status,
        public array $trigger,
        public ?array $subject,
        public ?array $startedBy,
        public ?string $parent,
        public ?array $failure,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $endedAt,
        public ?string $deletedAt,
        public array $steps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'definition' => $this->definition,
            'version' => $this->version,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'subject' => $this->subject,
            'started_by' => $this->startedBy,
            'parent' => $this->parent,
            'failure' => $this->failure,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'deleted_at' => $this->deletedAt,
            'steps' => $this->steps,
        ];
    }
}
