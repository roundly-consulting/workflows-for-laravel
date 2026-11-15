<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One version of an exported definition: its number, status and dates, and the version as a
 * portable template (`versions()->import()` takes it back).
 */
final readonly class ExportedVersion
{
    public function __construct(
        public int $number,
        public string $status,
        public ?string $publishedAt,
        public ?string $createdAt,
        public ?string $deletedAt,
        public TemplatePayload $template,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'published_at' => $this->publishedAt,
            'created_at' => $this->createdAt,
            'deleted_at' => $this->deletedAt,
            'template' => $this->template->toArray(),
        ];
    }
}
