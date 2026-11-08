<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * A portable `roundly.workflows.template/v1` document: the definition's key, name, subject type
 * (registry key) and description, the graph, and the paths a review should check on import. No
 * owner, no source ids, no class names.
 */
final readonly class TemplatePayload
{
    public const string SCHEMA = 'roundly.workflows.template/v1';

    /**
     * @param  array<string, mixed>  $graph
     * @param  list<array{node: string, path: string, reason: string}>  $review
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $subjectType,
        public ?string $description,
        public array $graph,
        public string $exportedAt,
        public string $checksum,
        public array $review = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'definition' => [
                'key' => $this->key,
                'name' => $this->name,
                'subject_type' => $this->subjectType,
                'description' => $this->description,
            ],
            'graph' => $this->graph,
            'review' => $this->review,
            'exported_at' => $this->exportedAt,
            'source' => ['checksum' => $this->checksum],
        ];
    }
}
