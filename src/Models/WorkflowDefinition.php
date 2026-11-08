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
use RoundlyConsulting\PackageToolkit\Concerns\HasConfigurableKey;
use RoundlyConsulting\PackageToolkit\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowDefinitionFactory;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * A named workflow of one owner (or app-wide) for one subject type, pointing at its current
 * published version.
 *
 * @property int|string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string $owner_scope
 * @property string|null $subject_key
 * @property DefinitionStatus $status
 * @property int|string|null $current_version_id
 * @property string|null $webhook_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $owner
 * @property-read WorkflowVersion|null $currentVersion
 */
class WorkflowDefinition extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowDefinitionFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    protected function primaryKeyTypeConfigKey(): string
    {
        return 'workflows.primary_key_type';
    }

    public function getTable(): string
    {
        return WorkflowsConfig::table('definitions');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowModels::version(), 'definition_id');
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::version(), 'current_version_id');
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowModels::instance(), 'definition_id');
    }

    public function isActive(): bool
    {
        return $this->status === DefinitionStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DefinitionStatus::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowDefinitionFactory
    {
        return WorkflowDefinitionFactory::new();
    }
}
