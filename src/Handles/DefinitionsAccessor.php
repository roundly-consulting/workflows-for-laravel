<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Definitions\CreateDefinitionAction;
use RoundlyConsulting\Workflows\Actions\Definitions\RestoreDefinitionAction;
use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Creating, finding, listing and restoring definitions, always within one owner (null =
 * app-wide).
 */
final readonly class DefinitionsAccessor
{
    public function __construct(
        private WorkflowsManager $manager,
    ) {}

    public function create(string $key, string $name, ?string $subjectType = null, ?Model $owner = null, ?string $description = null): ?WorkflowDefinition
    {
        return $this->createWith(new CreateDefinitionData($key, $name, $subjectType, $owner, $description));
    }

    public function createWith(CreateDefinitionData $data): ?WorkflowDefinition
    {
        return $this->manager->perform(
            WorkflowOperation::CreateDefinition,
            CreateDefinitionAction::class,
            static fn (CreateDefinitionAction $action): WorkflowDefinition => $action->execute($data),
            ['data' => $data, 'key' => $data->key],
        );
    }

    public function find(string $key, ?Model $owner = null): ?WorkflowDefinition
    {
        $definition = Definitions::query($owner)->where('key', $key)->first();

        return $definition instanceof WorkflowDefinition ? $definition : null;
    }

    public function exists(string $key, ?Model $owner = null): bool
    {
        return Definitions::exists($key, $owner);
    }

    /**
     * @return Collection<int, WorkflowDefinition>
     */
    public function all(?Model $owner = null): Collection
    {
        return Definitions::query($owner)->orderBy('key')->get();
    }

    /**
     * Bring back a soft-deleted definition (its key is still taken while trashed).
     */
    public function restore(string $key, ?Model $owner = null): ?WorkflowDefinition
    {
        return $this->manager->perform(
            WorkflowOperation::RestoreDefinition,
            RestoreDefinitionAction::class,
            static fn (RestoreDefinitionAction $action): WorkflowDefinition => $action->execute($key, $owner),
            ['key' => $key],
        );
    }
}
