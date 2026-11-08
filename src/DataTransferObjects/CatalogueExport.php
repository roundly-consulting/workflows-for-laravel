<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * The step catalogue export: every type, plus the shared `$defs` their schemas reference.
 */
final readonly class CatalogueExport
{
    /**
     * @param  list<CatalogueEntry>  $types
     * @param  array<string, mixed>  $definitions
     */
    public function __construct(
        public array $types,
        public array $definitions,
    ) {}

    public function find(string $key): ?CatalogueEntry
    {
        foreach ($this->types as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{types: list<array<string, mixed>>, definitions: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'types' => array_map(static fn (CatalogueEntry $type): array => $type->toArray(), $this->types),
            'definitions' => $this->definitions,
        ];
    }
}
