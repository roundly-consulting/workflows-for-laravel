<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Exceptions\InvalidTemplateException;
use RoundlyConsulting\Workflows\Graph\GraphParser;

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
     * @param  list<ReviewFlag>  $review
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
     * A template from its array form (a decoded file or request body); a malformed one throws.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidTemplateException
     */
    public static function fromArray(array $payload): self
    {
        $definition = $payload['definition'] ?? null;

        if (($payload['schema'] ?? null) !== self::SCHEMA || ! is_array($definition)
            || ! is_string($definition['key'] ?? null) || ! is_string($definition['name'] ?? null) || ! is_array($payload['graph'] ?? null)) {
            throw InvalidTemplateException::because('schema');
        }

        $subjectType = $definition['subject_type'] ?? null;
        $description = $definition['description'] ?? null;
        $checksum = is_array($payload['source'] ?? null) ? ($payload['source']['checksum'] ?? '') : '';

        return new self(
            key: $definition['key'],
            name: $definition['name'],
            subjectType: is_string($subjectType) ? $subjectType : null,
            description: is_string($description) ? $description : null,
            graph: GraphParser::stringKeyed($payload['graph']),
            exportedAt: is_string($payload['exported_at'] ?? null) ? $payload['exported_at'] : '',
            checksum: is_string($checksum) ? $checksum : '',
        );
    }

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
            'review' => array_map(static fn (ReviewFlag $flag): array => $flag->toArray(), $this->review),
            'exported_at' => $this->exportedAt,
            'source' => ['checksum' => $this->checksum],
        ];
    }
}
