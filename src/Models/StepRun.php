<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\PackageToolkit\Concerns\HasConfigurableKey;
use RoundlyConsulting\PackageToolkit\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Database\Factories\StepRunFactory;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * One attempt of one visit of one node: the step log. Every attempt of a visit shares the
 * visit's idempotency key.
 *
 * @property int|string $id
 * @property int|string $instance_id
 * @property string $node_id
 * @property string $node_type
 * @property int $visit
 * @property int $attempt
 * @property StepRunStatus $status
 * @property string $idempotency_key
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $outcome
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool|null $error_retryable
 * @property string|null $claim_token
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkflowInstance|null $instance
 */
class StepRun extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<StepRunFactory> */
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
        return WorkflowsConfig::table('step_runs');
    }

    /**
     * The attempt's error code as its FailureCode case, a host code as a string, or null.
     */
    public function error(): FailureCode|string|null
    {
        return $this->error_code === null ? null : FailureCode::fromStored($this->error_code);
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowModels::instance(), 'instance_id');
    }

    /**
     * @return HasMany<WorkflowWait, $this>
     */
    public function waits(): HasMany
    {
        return $this->hasMany(WorkflowModels::wait(), 'step_run_id');
    }

    /**
     * @return HasMany<WorkflowEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(WorkflowModels::escalation(), 'step_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visit' => 'integer',
            'attempt' => 'integer',
            'status' => StepRunStatus::class,
            'input' => 'array',
            'output' => 'array',
            'error_retryable' => 'boolean',
            'lease_until' => UtcDateTime::class,
            'scheduled_for' => UtcDateTime::class,
            'dispatched_at' => UtcDateTime::class,
            'started_at' => UtcDateTime::class,
            'finished_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): StepRunFactory
    {
        return StepRunFactory::new();
    }
}
