<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder;
use RoundlyConsulting\Workflows\Contracts\DescribesWorkflowSubject;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\UnknownSubjectTypeException;

/**
 * The subject (and owner) types the app registered, keyed by registry key. Loaded lazily (the
 * config list on first use), so types registered in the app's own providers are seen.
 *
 * @internal
 */
final class SubjectRegistry
{
    /** @var array<string, SubjectTypeDefinition> */
    private array $types = [];

    private bool $loaded = false;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(SubjectTypeBuilder|SubjectTypeDefinition $type): SubjectTypeDefinition
    {
        $this->load();

        return $this->add($type);
    }

    public function find(string $key): ?SubjectTypeDefinition
    {
        $this->load();

        return $this->types[$key] ?? null;
    }

    /**
     * @throws UnknownSubjectTypeException
     */
    public function get(string $key): SubjectTypeDefinition
    {
        return $this->find($key) ?? throw UnknownSubjectTypeException::key($key, array_keys($this->types));
    }

    /**
     * The type a model is registered under (an exact class match first, then a parent class).
     */
    public function forModel(Model|string $model): ?SubjectTypeDefinition
    {
        $this->load();
        $class = $model instanceof Model ? $model::class : $model;

        foreach ($this->types as $type) {
            if ($type->model === $class) {
                return $type;
            }
        }

        foreach ($this->types as $type) {
            if (is_a($class, $type->model, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<string, SubjectTypeDefinition>
     */
    public function all(): array
    {
        $this->load();

        return $this->types;
    }

    private function add(SubjectTypeBuilder|SubjectTypeDefinition $type): SubjectTypeDefinition
    {
        $definition = $type instanceof SubjectTypeBuilder ? $type->build() : $type;

        KeyTypes::assertModel($definition->ownerType ? 'owner' : 'subject', $definition->model);

        $this->types[$definition->key] = $definition;

        return $definition;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach (WorkflowsConfig::subjects() as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, DescribesWorkflowSubject::class)) {
                throw InvalidWorkflowsConfigurationException::notAnImplementation('workflows.subjects', DescribesWorkflowSubject::class, $class);
            }

            $describer = $this->container->make($class);

            if ($describer instanceof DescribesWorkflowSubject) {
                $this->add($describer->describe());
            }
        }
    }
}
