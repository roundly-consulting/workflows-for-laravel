<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Definitions\CreateDefinitionAction;
use RoundlyConsulting\Workflows\Actions\Definitions\RestoreDefinitionAction;
use RoundlyConsulting\Workflows\Actions\Definitions\SyncDefinitionsAction;
use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\DataTransferObjects\SyncReport;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\Testing\StandIns;
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

    public function create(string $key, string $name, ?string $subjectType = null, ?Model $owner = null, ?string $description = null): WorkflowDefinition
    {
        return $this->createWith(new CreateDefinitionData($key, $name, $subjectType, $owner, $description));
    }

    public function createWith(CreateDefinitionData $data): WorkflowDefinition
    {
        return $this->manager->perform(
            WorkflowOperation::CreateDefinition,
            CreateDefinitionAction::class,
            static fn (CreateDefinitionAction $action): WorkflowDefinition => $action->execute($data),
            static fn (): WorkflowDefinition => StandIns::definition($data->key, $data->name, $data->owner, $data->subjectType, $data->description),
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
     * Create, draft and (with `publish`) publish definitions from `*.json` files in the template
     * envelope — a directory or one file; unchanged files do nothing, nothing is ever retired.
     * The path defaults to `workflows.sync.path`.
     */
    public function sync(?string $path = null, ?Model $owner = null, bool $publish = false, bool $dryRun = false): SyncReport
    {
        $path ??= WorkflowsConfig::syncPath();

        return $this->manager->perform(
            WorkflowOperation::Sync,
            SyncDefinitionsAction::class,
            static fn (SyncDefinitionsAction $action): SyncReport => $action->execute($path, $owner, $publish, $dryRun),
            static fn (): SyncReport => new SyncReport(dryRun: $dryRun),
            ['path' => $path, 'owner' => $owner, 'publish' => $publish, 'dry_run' => $dryRun],
        );
    }

    /**
     * Bring back a soft-deleted definition (its key is still taken while trashed).
     */
    public function restore(string $key, ?Model $owner = null): WorkflowDefinition
    {
        return $this->manager->perform(
            WorkflowOperation::RestoreDefinition,
            RestoreDefinitionAction::class,
            static fn (RestoreDefinitionAction $action): WorkflowDefinition => $action->execute($key, $owner),
            static fn (): WorkflowDefinition => StandIns::definition($key, owner: $owner),
            ['key' => $key, 'owner' => $owner],
        );
    }
}
