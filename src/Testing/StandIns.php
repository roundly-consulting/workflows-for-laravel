<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\ImportResult;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * What a faked mutation returns instead of null: unsaved models (`exists === false`) built
 * through the configured model classes, carrying what the call identified. Assert with the fake,
 * never on the database.
 *
 * @internal
 */
final class StandIns
{
    public static function definition(string $key, ?string $name = null, ?Model $owner = null, ?string $subjectType = null, ?string $description = null): WorkflowDefinition
    {
        $model = WorkflowModels::definition();
        $definition = new $model;
        $definition->key = $key;
        $definition->name = $name ?? $key;
        $definition->description = $description;
        $definition->owner_type = $owner?->getMorphClass();
        $definition->owner_scope = Owners::scope($owner);
        $definition->subject_key = $subjectType;
        $definition->status = DefinitionStatus::Draft;

        return $definition;
    }

    /**
     * An unsaved copy of a stored definition: its key, name, owner and status, nothing changed.
     */
    public static function of(WorkflowDefinition $definition): WorkflowDefinition
    {
        $copy = $definition->replicate();
        $copy->exists = false;

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    public static function version(array $graph = []): WorkflowVersion
    {
        $model = WorkflowModels::version();
        $version = new $model;
        $version->number = 0;
        $version->status = VersionStatus::Draft;
        $version->graph = $graph;

        return $version;
    }

    /**
     * @param  TemplatePayload|array<string, mixed>  $payload
     */
    public static function import(TemplatePayload|array $payload, ?Model $owner, ?string $key): ImportResult
    {
        $definition = is_array($payload) && is_array($payload['definition'] ?? null) ? $payload['definition'] : [];
        $graph = is_array($payload) ? ($payload['graph'] ?? []) : $payload->graph;
        $templateKey = $payload instanceof TemplatePayload ? $payload->key : ($definition['key'] ?? '');
        $name = $payload instanceof TemplatePayload ? $payload->name : ($definition['name'] ?? null);

        return new ImportResult(
            self::definition($key ?? (is_string($templateKey) ? $templateKey : ''), is_string($name) ? $name : null, $owner),
            self::version(is_array($graph) ? GraphParser::stringKeyed($graph) : []),
            [],
            [],
        );
    }
}
