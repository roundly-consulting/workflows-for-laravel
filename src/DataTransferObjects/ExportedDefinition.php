<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One definition of an owner export, with every version it has (drafts and deleted ones
 * included, oldest first).
 */
final readonly class ExportedDefinition
{
    /**
     * @param  list<ExportedVersion>  $versions
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $subjectType,
        public ?string $description,
        public string $status,
        public ?string $createdAt,
        public ?string $deletedAt,
        public array $versions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'subject_type' => $this->subjectType,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'deleted_at' => $this->deletedAt,
            'versions' => array_map(static fn (ExportedVersion $version): array => $version->toArray(), $this->versions),
        ];
    }
}
