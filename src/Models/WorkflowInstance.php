<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Concerns\HasConfigurableKey;
use RoundlyConsulting\Workflows\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowInstanceFactory;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * One run of one version for one subject (or none). It always finishes on the version it
 * started on, inside its owner's context.
 *
 * @property int|string $id
 * @property string $ulid
 * @property int|string $definition_id
 * @property int|string $version_id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $subject_key
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property TriggerType $trigger_type
 * @property string|null $trigger_key
 * @property array<string, mixed>|null $trigger_payload
 * @property string|null $idempotency_digest
 * @property InstanceStatus $status
 * @property string|null $current_node_id
 * @property int $steps_count
 * @property int $depth
 * @property int|string|null $parent_instance_id
 * @property int|string|null $parent_step_run_id
 * @property int|string|null $root_instance_id
 * @property ActorMode $actor_mode
 * @property string|null $starter_type
 * @property int|string|null $starter_id
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $failed_node_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $lock_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkflowDefinition|null $definition
 * @property-read WorkflowVersion|null $version
 * @property-read Model|null $owner
 * @property-read Model|null $subject
 * @property-read Model|null $starter
 * @property-read WorkflowInstance|null $parent
 */
class WorkflowInstance extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowInstanceFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    /**
     * The failure code as its FailureCode case (a `code:detail` code by its prefix), a host
     * step's own code as a string, or null when the instance has not failed.
     */
    public function failure(): FailureCode|string|null
    {
        return $this->failure_code === null ? null : FailureCode::fromStored($this->failure_code);
    }

    public function getTable(): string
    {
        return WorkflowsConfig::table('instances');
    }

    /**
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::definition(), 'definition_id')->withTrashed();
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::version(), 'version_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function starter(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::instance(), 'parent_instance_id');
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(WorkflowModels::instance(), 'parent_instance_id');
    }

    /**
     * @return HasMany<StepRun, $this>
     */
    public function stepRuns(): HasMany
    {
        return $this->hasMany(WorkflowModels::stepRun(), 'instance_id');
    }

    /**
     * @return HasMany<WorkflowWait, $this>
     */
    public function waits(): HasMany
    {
        return $this->hasMany(WorkflowModels::wait(), 'instance_id');
    }

    /**
     * @return HasMany<WorkflowEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(WorkflowModels::escalation(), 'instance_id');
    }

    /**
     * The node ids this instance visited, in order (each visit once, retries collapsed).
     *
     * @return list<string>
     */
    public function path(): array
    {
        $path = [];
        $seen = [];

        foreach ($this->stepRuns()->orderBy('id')->get() as $run) {
            $visit = $run->node_id.'#'.$run->visit;

            if (! isset($seen[$visit])) {
                $seen[$visit] = true;
                $path[] = $run->node_id;
            }
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => TriggerType::class,
            'trigger_payload' => 'array',
            'status' => InstanceStatus::class,
            'steps_count' => 'integer',
            'depth' => 'integer',
            'actor_mode' => ActorMode::class,
            'lock_version' => 'integer',
            'started_at' => UtcDateTime::class,
            'ended_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowInstanceFactory
    {
        return WorkflowInstanceFactory::new();
    }
}
