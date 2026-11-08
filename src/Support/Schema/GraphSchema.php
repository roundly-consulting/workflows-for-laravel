<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schema;

use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * The published JSON Schema (draft 2020-12) of the graph document and of the template payload.
 * Node configs are typed per step type: see the catalogue export.
 *
 * @internal
 */
final class GraphSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function graph(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://roundly-consulting.com/schemas/'.Graph::SCHEMA.'.json',
            'title' => 'Workflow graph',
            'type' => 'object',
            'required' => ['schema', 'nodes', 'edges'],
            'properties' => [
                'schema' => ['const' => Graph::SCHEMA],
                'settings' => [
                    'type' => 'object',
                    'properties' => [
                        'actor' => ['enum' => ['system', 'on_behalf_of'], 'default' => 'system'],
                        'timezone' => ['type' => 'string', 'description' => 'IANA timezone; default workflows.timezone, else app.timezone.'],
                        'calendar' => ['type' => 'string', 'description' => 'Default business calendar (weekdays, opening_hours:<name> or a host calendar).'],
                        'limits' => ['type' => 'object', 'properties' => ['max_steps' => ['type' => 'integer', 'minimum' => 2, 'maximum' => WorkflowsConfig::maxSteps()]], 'additionalProperties' => false],
                        'retry' => ['$ref' => '#/$defs/retry'],
                    ],
                    'additionalProperties' => false,
                ],
                'nodes' => [
                    'type' => 'array',
                    'maxItems' => WorkflowsConfig::graphMaxNodes(),
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'type'],
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                            'type' => ['type' => 'string', 'description' => 'A step catalogue key.'],
                            'config' => ['type' => 'object', 'description' => 'Validated against the type\'s config schema.'],
                            'retry' => ['$ref' => '#/$defs/retry'],
                            'escalations' => ['type' => 'array', 'maxItems' => WorkflowsConfig::escalationsMaxLevels(), 'items' => ['$ref' => '#/$defs/escalation']],
                            'ui' => ['type' => 'object', 'description' => 'Free-form (position, label); ignored by the engine.'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'edges' => [
                    'type' => 'array',
                    'maxItems' => WorkflowsConfig::graphMaxEdges(),
                    'items' => [
                        'type' => 'object',
                        'required' => ['from', 'to'],
                        'properties' => [
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                            'outcome' => ['type' => 'string', 'description' => 'Required unless the source type\'s only outcome is next.'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
            '$defs' => [
                ...SharedDefinitions::all(),
                'retry' => [
                    'type' => 'object',
                    'properties' => [
                        'max_attempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => WorkflowsConfig::maxAttemptsCap()],
                        'backoff' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]],
                    ],
                    'additionalProperties' => false,
                ],
                'escalation' => [
                    'type' => 'object',
                    'required' => ['after', 'actions'],
                    'properties' => [
                        'after' => ['$ref' => '#/$defs/duration'],
                        'actions' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => 5,
                            'items' => [
                                'type' => 'object',
                                'required' => ['type'],
                                'properties' => ['type' => ['enum' => ['notify', 'reassign', 'start_workflow']]],
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function template(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://roundly-consulting.com/schemas/'.TemplatePayload::SCHEMA.'.json',
            'title' => 'Workflow template',
            'type' => 'object',
            'required' => ['schema', 'definition', 'graph'],
            'properties' => [
                'schema' => ['const' => TemplatePayload::SCHEMA],
                'definition' => [
                    'type' => 'object',
                    'required' => ['key', 'name'],
                    'properties' => [
                        'key' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{0,99}$'],
                        'name' => ['type' => 'string'],
                        'subject_type' => ['type' => ['string', 'null']],
                        'description' => ['type' => ['string', 'null']],
                    ],
                ],
                'graph' => ['$ref' => Graph::SCHEMA],
                'review' => ['type' => 'array'],
                'exported_at' => ['type' => 'string', 'format' => 'date-time'],
                'source' => ['type' => 'object', 'properties' => ['checksum' => ['type' => 'string']]],
            ],
        ];
    }
}
