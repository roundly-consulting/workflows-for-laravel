<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A pending timer due in an hour.
 *
 * @extends Factory<WorkflowWait>
 */
final class WorkflowWaitFactory extends Factory
{
    /** @var class-string<WorkflowWait> */
    protected $model = WorkflowWait::class;

    public function modelName(): string
    {
        return WorkflowModels::wait();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'instance_id' => WorkflowInstance::factory(),
            'step_run_id' => static fn (array $attributes): mixed => StepRun::factory()->create(['instance_id' => $attributes['instance_id']])->getKey(),
            'kind' => WaitKind::Timer,
            'due_at' => Clock::now()->addHour(),
            'status' => WaitStatus::Pending,
        ];
    }
}
