<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\PackageToolkit\Concerns\HasConfigurableKey;
use RoundlyConsulting\PackageToolkit\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowWaitFactory;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * A persisted wait the engine wakes on: a timer, an event key, a condition poll, a retry or a
 * timeout. Never a sleeping process: it survives deploys and restarts.
 *
 * @property int|string $id
 * @property int|string $instance_id
 * @property int|string $step_run_id
 * @property WaitKind $kind
 * @property CarbonImmutable|null $due_at
 * @property string|null $event_key
 * @property array<string, mixed>|null $match
 * @property array<string, mixed>|null $condition
 * @property string|null $outcome
 * @property array<string, mixed>|null $payload
 * @property WaitStatus $status
 * @property string|null $claim_token
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $fired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkflowInstance|null $instance
 * @property-read StepRun|null $stepRun
 */
class WorkflowWait extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowWaitFactory> */
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
        return WorkflowsConfig::table('waits');
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::instance(), 'instance_id');
    }

    /**
     * @return BelongsTo<StepRun, $this>
     */
    public function stepRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::stepRun(), 'step_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WaitKind::class,
            'status' => WaitStatus::class,
            'match' => 'array',
            'condition' => 'array',
            'payload' => 'array',
            'due_at' => UtcDateTime::class,
            'claimed_at' => UtcDateTime::class,
            'fired_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowWaitFactory
    {
        return WorkflowWaitFactory::new();
    }
}
