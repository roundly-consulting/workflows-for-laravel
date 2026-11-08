<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\PackageToolkit\Concerns\HasConfigurableKey;
use RoundlyConsulting\PackageToolkit\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowIdempotencyKeyFactory;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * The idempotency ledger: start keys (one instance per trigger key) and effect keys (a step's
 * `once()` results). A ledger, so no soft deletes — the documented exception to the fleet rule.
 *
 * @property int|string $id
 * @property string $scope
 * @property string $key
 * @property int|string|null $definition_id
 * @property int|string|null $instance_id
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property-read WorkflowInstance|null $instance
 */
class WorkflowIdempotencyKey extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowIdempotencyKeyFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected function primaryKeyTypeConfigKey(): string
    {
        return 'workflows.primary_key_type';
    }

    public function getTable(): string
    {
        return WorkflowsConfig::table('idempotency_keys');
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::instance(), 'instance_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
            'expires_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowIdempotencyKeyFactory
    {
        return WorkflowIdempotencyKeyFactory::new();
    }
}
