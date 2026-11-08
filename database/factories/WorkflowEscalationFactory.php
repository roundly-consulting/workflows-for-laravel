<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A pending level-1 escalation due in a day, without actions.
 *
 * @extends Factory<WorkflowEscalation>
 */
final class WorkflowEscalationFactory extends Factory
{
    /** @var class-string<WorkflowEscalation> */
    protected $model = WorkflowEscalation::class;

    public function modelName(): string
    {
        return WorkflowModels::escalation();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'instance_id' => WorkflowInstance::factory(),
            'step_run_id' => static fn (array $attributes): mixed => StepRun::factory()->create(['instance_id' => $attributes['instance_id']])->getKey(),
            'level' => 1,
            'due_at' => Clock::now()->addDay(),
            'actions' => [],
            'status' => EscalationStatus::Pending,
            'attempts' => 0,
        ];
    }
}
