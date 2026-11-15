<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;

/**
 * The subject (and owner) types: register them, read them, and export a field schema for
 * condition builders and pickers (registry keys only, never class names). Pass the owner to
 * `export()` to include that owner's dynamic fields.
 */
final readonly class SubjectsAccessor
{
    public function __construct(
        private Container $container,
    ) {}

    public function register(SubjectTypeBuilder|SubjectTypeDefinition $type): SubjectTypeDefinition
    {
        return $this->registry()->register($type);
    }

    public function find(string $key): ?SubjectTypeDefinition
    {
        return $this->registry()->find($key);
    }

    /**
     * The type's field schema with the owner's dynamic fields (`dynamicFields()`) added — those
     * of app-wide definitions without an owner — exactly what publish validation accepts.
     */
    public function export(string $key, ?Model $owner = null): SubjectTypeDefinition
    {
        return $this->registry()->get($key)->forOwner($owner);
    }

    /**
     * @return array<string, SubjectTypeDefinition>
     */
    public function all(): array
    {
        return $this->registry()->all();
    }

    private function registry(): SubjectRegistry
    {
        return $this->container->make(SubjectRegistry::class);
    }
}
