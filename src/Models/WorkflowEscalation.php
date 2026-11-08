<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Workflows\Casts\UtcDateTime;
use RoundlyConsulting\Workflows\Concerns\HasConfigurableKey;
use RoundlyConsulting\Workflows\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Workflows\Database\Factories\WorkflowEscalationFactory;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * One escalation level of one step visit: its due time and the actions it runs. Only the next
 * pending level exists as a row; it is cancelled when the step finishes.
 *
 * @property int|string $id
 * @property int|string $instance_id
 * @property int|string $step_run_id
 * @property int $level
 * @property CarbonImmutable $due_at
 * @property list<array<string, mixed>> $actions
 * @property EscalationStatus $status
 * @property int $attempts
 * @property string|null $claim_token
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $fired_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WorkflowInstance|null $instance
 * @property-read StepRun|null $stepRun
 */
class WorkflowEscalation extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<WorkflowEscalationFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return WorkflowsConfig::table('escalations');
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
            'level' => 'integer',
            'actions' => 'array',
            'status' => EscalationStatus::class,
            'attempts' => 'integer',
            'due_at' => UtcDateTime::class,
            'claimed_at' => UtcDateTime::class,
            'fired_at' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): WorkflowEscalationFactory
    {
        return WorkflowEscalationFactory::new();
    }
}
