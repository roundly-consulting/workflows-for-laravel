<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Graph\Canonicalizer;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A draft version 1 holding the minimal graph; pass a definition with `for()`.
 *
 * @extends Factory<WorkflowVersion>
 */
final class WorkflowVersionFactory extends Factory
{
    /** @var class-string<WorkflowVersion> */
    protected $model = WorkflowVersion::class;

    public function modelName(): string
    {
        return WorkflowModels::version();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'definition_id' => WorkflowDefinition::factory(),
            'number' => 1,
            'status' => VersionStatus::Draft,
            'schema_version' => Graph::SCHEMA,
            'graph' => GraphParser::minimal(),
            'trigger' => ['trigger' => 'manual'],
            'trigger_type' => TriggerType::Manual,
            'actor_mode' => ActorMode::System,
            'settings' => [],
            'checksum' => Canonicalizer::checksum(GraphParser::minimal()),
        ];
    }
}
