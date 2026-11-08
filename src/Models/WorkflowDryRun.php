<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\PackageToolkit\Concerns\HasConfigurableKey;
use RoundlyConsulting\PackageToolkit\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowDryRunFactory;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * The optional log of one dry run: what it was run against and the path it took. The only row a
 * dry run ever writes.
 *
 * @property int|string $id
 * @property int|string $definition_id
 * @property int|string|null $version_id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property array<string, mixed>|null $input
 * @property array<string, mixed> $result
 * @property string|null $requester_type
 * @property int|string|null $requester_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkflowDefinition|null $definition
 */
class WorkflowDryRun extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowDryRunFactory> */
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
        return WorkflowsConfig::table('dry_runs');
    }

    /**
     * @return BelongsTo<WorkflowDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::definition(), 'definition_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowDryRunFactory
    {
        return WorkflowDryRunFactory::new();
    }
}
