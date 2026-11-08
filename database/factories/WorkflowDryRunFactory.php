<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowDryRun;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A logged dry run of a fresh definition.
 *
 * @extends Factory<WorkflowDryRun>
 */
final class WorkflowDryRunFactory extends Factory
{
    /** @var class-string<WorkflowDryRun> */
    protected $model = WorkflowDryRun::class;

    public function modelName(): string
    {
        return WorkflowModels::dryRun();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'definition_id' => WorkflowDefinition::factory(),
            'input' => [],
            'result' => ['valid' => true, 'started' => true, 'path' => []],
        ];
    }
}
