<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Graph\Canonicalizer;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;

/**
 * Draft versions: finding (or creating) the one draft of a definition and deriving the indexed
 * trigger, actor and settings columns from a graph.
 *
 * @internal
 */
final class Drafts
{
    public static function find(WorkflowDefinition $definition): ?WorkflowVersion
    {
        $model = WorkflowModels::version();
        $draft = $model::query()->where('definition_id', Keys::of($definition))->where('status', VersionStatus::Draft->value)->first();

        return $draft instanceof WorkflowVersion ? $draft : null;
    }

    /**
     * The draft, or a new one copied from the current version (numbered max + 1).
     */
    public static function findOrCreate(WorkflowDefinition $definition): WorkflowVersion
    {
        $draft = self::find($definition);

        if ($draft !== null) {
            return $draft;
        }

        $model = WorkflowModels::version();
        $current = $definition->currentVersion;
        $max = $model::query()->withTrashed()->where('definition_id', Keys::of($definition))->max('number');
        $number = (is_numeric($max) ? (int) $max : 0) + 1;

        $draft = new $model;
        $draft->definition_id = Keys::of($definition);
        $draft->number = $number;
        $draft->status = VersionStatus::Draft;
        self::fill($draft, $current instanceof WorkflowVersion ? $current->graph : GraphParser::minimal());
        $draft->save();

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    public static function fill(WorkflowVersion $version, array $graph): void
    {
        $parsed = GraphParser::parse($graph);
        $trigger = $parsed->trigger()->config ?? ['trigger' => 'manual'];
        $type = TriggerType::tryFrom(is_string($trigger['trigger'] ?? null) ? $trigger['trigger'] : 'manual') ?? TriggerType::Manual;
        $actor = ActorMode::tryFrom(is_string($parsed->settings['actor'] ?? null) ? $parsed->settings['actor'] : 'system') ?? ActorMode::System;

        $version->schema_version = $parsed->schema !== '' ? substr($parsed->schema, 0, 32) : Graph::SCHEMA;
        $version->graph = $graph;
        $version->trigger = $trigger;
        $version->trigger_type = $type;
        $version->trigger_key = $type === TriggerType::Event && is_string($trigger['event'] ?? null) ? substr($trigger['event'], 0, 191) : null;
        $version->actor_mode = $actor;
        $version->settings = $parsed->settings;
        $version->checksum = Canonicalizer::checksum($graph);
    }
}
