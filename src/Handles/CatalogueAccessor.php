<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Workflows\DataTransferObjects\CatalogueEntry;
use RoundlyConsulting\Workflows\DataTransferObjects\CatalogueExport;
use RoundlyConsulting\Workflows\Steps\Builtin\TransitionStep;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Schema\SharedDefinitions;
use RoundlyConsulting\Workflows\Support\StepCatalogue;

/**
 * The step catalogue: register host step types, read them, and export every type with its
 * config JSON schema, labels and outcomes for a builder UI.
 */
final readonly class CatalogueAccessor
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @param  class-string<StepType>|StepType  $type
     */
    public function register(string|StepType $type): StepType
    {
        return $this->catalogue()->register($type);
    }

    /**
     * @return array<string, StepType>
     */
    public function all(): array
    {
        return $this->catalogue()->all();
    }

    public function find(string $key): ?StepType
    {
        return $this->catalogue()->find($key);
    }

    public function has(string $key): bool
    {
        return $this->catalogue()->has($key);
    }

    public function export(): CatalogueExport
    {
        $entries = [];

        foreach ($this->catalogue()->all() as $key => $type) {
            $entries[] = new CatalogueEntry(
                key: $key,
                label: $type->label(),
                description: $type->description(),
                category: $type->category(),
                configSchema: $type->configSchema(),
                outcomes: $type->outcomes([]),
                sideEffects: $type->hasSideEffects(),
                escalations: $type->supportsEscalations(),
                available: ! $type instanceof TransitionStep || $type->available(),
                package: StepCatalogue::ADAPTERS[$key] ?? null,
                outputSchema: $type->outputSchema(),
            );
        }

        return new CatalogueExport($entries, SharedDefinitions::all());
    }

    private function catalogue(): StepCatalogue
    {
        return $this->container->make(StepCatalogue::class);
    }
}
