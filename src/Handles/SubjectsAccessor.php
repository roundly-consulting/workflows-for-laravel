<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;

/**
 * The subject (and owner) types: register them, read them, and export a field schema for
 * condition builders and pickers (registry keys only, never class names).
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

    public function export(string $key): SubjectTypeDefinition
    {
        return $this->registry()->get($key);
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
