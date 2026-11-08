<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * An app-wide draft definition without a subject type.
 *
 * @extends Factory<WorkflowDefinition>
 */
final class WorkflowDefinitionFactory extends Factory
{
    /** @var class-string<WorkflowDefinition> */
    protected $model = WorkflowDefinition::class;

    public function modelName(): string
    {
        return WorkflowModels::definition();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'flow-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'name' => 'Flow',
            'owner_scope' => '*',
            'status' => DefinitionStatus::Draft,
        ];
    }
}
