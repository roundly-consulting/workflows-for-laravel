<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A running manual instance of a fresh version.
 *
 * @extends Factory<WorkflowInstance>
 */
final class WorkflowInstanceFactory extends Factory
{
    /** @var class-string<WorkflowInstance> */
    protected $model = WorkflowInstance::class;

    public function modelName(): string
    {
        return WorkflowModels::instance();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'definition_id' => WorkflowDefinition::factory(),
            'version_id' => static fn (array $attributes): mixed => WorkflowVersion::factory()->create(['definition_id' => $attributes['definition_id']])->getKey(),
            'trigger_type' => TriggerType::Manual,
            'status' => InstanceStatus::Running,
            'actor_mode' => ActorMode::System,
            'steps_count' => 0,
            'depth' => 0,
            'started_at' => Clock::now(),
        ];
    }
}
