<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One step type as a builder UI needs it: key, label, description, category, config JSON schema,
 * outcomes (for the default config), side effects, escalation support and availability (its
 * bridge package installed).
 */
final readonly class CatalogueEntry
{
    /**
     * @param  array<string, mixed>  $configSchema
     * @param  list<array{key: string, label: string, required: bool}>  $outcomes
     * @param  array<string, mixed>|null  $outputSchema
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $description,
        public string $category,
        public array $configSchema,
        public array $outcomes,
        public bool $sideEffects,
        public bool $escalations,
        public bool $available,
        public ?string $package = null,
        public ?array $outputSchema = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'category' => $this->category,
            'config_schema' => $this->configSchema,
            'output_schema' => $this->outputSchema,
            'outcomes' => $this->outcomes,
            'side_effects' => $this->sideEffects,
            'escalations' => $this->escalations,
            'available' => $this->available,
            'package' => $this->package,
        ];
    }
}
