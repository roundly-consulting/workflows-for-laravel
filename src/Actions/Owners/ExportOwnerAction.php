<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Owners;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Workflows\Actions\Versions\ExportTemplateAction;
use RoundlyConsulting\Workflows\DataTransferObjects\ExportedDefinition;
use RoundlyConsulting\Workflows\DataTransferObjects\ExportedVersion;
use RoundlyConsulting\Workflows\DataTransferObjects\OwnerExport;
use RoundlyConsulting\Workflows\DataTransferObjects\RunSummary;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * Exports everything the package holds for one owner (a GDPR or company export): its
 * definitions — deleted ones too — with every version as a portable template (the same payload
 * `versions()->export()` gives, so each imports back), and a summary of every instance it owns,
 * app-wide definitions' runs on its subjects included: status, trigger, timestamps, subject and
 * starter references, and each step's outcome. Inputs, outputs, trigger payloads, failure
 * messages, failure details and class names stay out.
 */
final readonly class ExportOwnerAction
{
    private const int CHUNK = 200;

    public function __construct(
        private ExportTemplateAction $templates,
        private SubjectRegistry $subjects,
    ) {}

    public function execute(Model $owner): OwnerExport
    {
        return new OwnerExport(
            owner: ['type' => $this->subjects->forModel($owner)->key ?? class_basename($owner), 'id' => Keys::of($owner)],
            exportedAt: Clock::now()->toIso8601String(),
            definitions: $this->definitions($owner),
            runs: $this->runs($owner),
        );
    }

    /**
     * @return list<ExportedDefinition>
     */
    private function definitions(Model $owner): array
    {
        $versionModel = WorkflowModels::version();
        $exported = [];

        foreach (Definitions::query($owner, withTrashed: true)->orderBy('key')->get() as $definition) {
            $versions = [];

            foreach ($versionModel::query()->withTrashed()->where('definition_id', Keys::of($definition))->orderBy('number')->get() as $version) {
                // A deleted definition's versions still export: hand them their definition.
                $version->setRelation('definition', $definition);
                $versions[] = new ExportedVersion(
                    number: $version->number,
                    status: $version->status->value,
                    publishedAt: self::date($version->published_at),
                    createdAt: self::date($version->created_at),
                    deletedAt: self::date($version->deleted_at),
                    template: $this->templates->execute($version),
                );
            }

            $exported[] = new ExportedDefinition(
                key: $definition->key,
                name: $definition->name,
                subjectType: $definition->subject_key,
                description: $definition->description,
                status: $definition->status->value,
                createdAt: self::date($definition->created_at),
                deletedAt: self::date($definition->deleted_at),
                versions: $versions,
            );
        }

        return $exported;
    }

    /**
     * @return list<RunSummary>
     */
    private function runs(Model $owner): array
    {
        $model = WorkflowModels::instance();
        $runs = [];

        $model::query()
            ->withTrashed()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', Keys::of($owner))
            ->with('definition')
            ->chunkById(self::CHUNK, function (Collection $instances) use (&$runs): void {
                /** @var Collection<int, WorkflowInstance> $instances */
                $versions = $this->versionNumbers($instances);
                $parents = $this->parentIds($instances);
                $steps = $this->steps($instances);

                foreach ($instances as $instance) {
                    $runs[] = $this->summary($instance, $versions, $parents, $steps[(string) Keys::of($instance)] ?? []);
                }
            });

        return $runs;
    }

    /**
     * @param  array<string, int>  $versions
     * @param  array<string, string>  $parents
     * @param  list<array{node: string, type: string, visit: int, attempt: int, status: string, outcome: string|null, error: string|null, started_at: string|null, finished_at: string|null}>  $steps
     */
    private function summary(WorkflowInstance $instance, array $versions, array $parents, array $steps): RunSummary
    {
        $definition = $instance->definition;

        return new RunSummary(
            id: $instance->ulid,
            definition: $definition instanceof WorkflowDefinition ? $definition->key : null,
            version: $versions[(string) $instance->version_id] ?? null,
            status: $instance->status->value,
            trigger: ['type' => $instance->trigger_type->value, 'key' => $instance->trigger_key],
            subject: $instance->subject_type === null || $instance->subject_id === null ? null : [
                'type' => $instance->subject_key ?? self::basename($instance->subject_type),
                'id' => $instance->subject_id,
            ],
            startedBy: $instance->starter_type === null || $instance->starter_id === null ? null : [
                'type' => self::basename($instance->starter_type),
                'id' => $instance->starter_id,
            ],
            parent: $instance->parent_instance_id === null ? null : ($parents[(string) $instance->parent_instance_id] ?? null),
            failure: $instance->failure_code === null ? null : ['code' => $instance->failure_code, 'node' => $instance->failed_node_id],
            createdAt: self::date($instance->created_at),
            startedAt: self::date($instance->started_at),
            endedAt: self::date($instance->ended_at),
            deletedAt: self::date($instance->deleted_at),
            steps: $steps,
        );
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return array<string, int> version id => number
     */
    private function versionNumbers(Collection $instances): array
    {
        $model = WorkflowModels::version();
        $numbers = [];

        foreach ($model::query()->withTrashed()->whereIn('id', $instances->pluck('version_id')->unique()->values()->all())->get(['id', 'number']) as $version) {
            /** @var WorkflowVersion $version */
            $numbers[(string) Keys::of($version)] = $version->number;
        }

        return $numbers;
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return array<string, string> parent id => parent ulid
     */
    private function parentIds(Collection $instances): array
    {
        $ids = $instances->pluck('parent_instance_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $model = WorkflowModels::instance();
        $parents = [];

        foreach ($model::query()->withTrashed()->whereIn('id', $ids)->get(['id', 'ulid']) as $parent) {
            /** @var WorkflowInstance $parent */
            $parents[(string) Keys::of($parent)] = $parent->ulid;
        }

        return $parents;
    }

    /**
     * @param  Collection<int, WorkflowInstance>  $instances
     * @return array<string, list<array{node: string, type: string, visit: int, attempt: int, status: string, outcome: string|null, error: string|null, started_at: string|null, finished_at: string|null}>>
     */
    private function steps(Collection $instances): array
    {
        $model = WorkflowModels::stepRun();
        $steps = [];

        $runs = $model::query()
            ->withTrashed()
            ->whereIn('instance_id', $instances->modelKeys())
            ->orderBy('id')
            ->get(['id', 'instance_id', 'node_id', 'node_type', 'visit', 'attempt', 'status', 'outcome', 'error_code', 'started_at', 'finished_at']);

        foreach ($runs as $run) {
            /** @var StepRun $run */
            $steps[(string) $run->instance_id][] = [
                'node' => $run->node_id,
                'type' => $run->node_type,
                'visit' => $run->visit,
                'attempt' => $run->attempt,
                'status' => $run->status->value,
                'outcome' => $run->outcome,
                'error' => $run->error_code,
                'started_at' => self::date($run->started_at),
                'finished_at' => self::date($run->finished_at),
            ];
        }

        return $steps;
    }

    /**
     * A stored morph type as a basename (`App\Models\User` → `User`, a morph alias as itself).
     */
    private static function basename(string $type): string
    {
        return class_basename(Relation::getMorphedModel($type) ?? $type);
    }

    private static function date(?CarbonImmutable $date): ?string
    {
        return $date?->toIso8601String();
    }
}
