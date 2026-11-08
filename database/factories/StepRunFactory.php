<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A queued first attempt of an `end` node visit.
 *
 * @extends Factory<StepRun>
 */
final class StepRunFactory extends Factory
{
    /** @var class-string<StepRun> */
    protected $model = StepRun::class;

    public function modelName(): string
    {
        return WorkflowModels::stepRun();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'instance_id' => WorkflowInstance::factory(),
            'node_id' => 'end',
            'node_type' => 'end',
            'visit' => 1,
            'attempt' => 1,
            'status' => StepRunStatus::Queued,
            'idempotency_key' => 'wf:'.Str::lower((string) Str::ulid()).':end:1',
        ];
    }
}
