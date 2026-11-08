<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schema;

/**
 * The `$defs` every step config schema may `$ref`: value, party, parties, duration, condition
 * and field. Exported with the catalogue so a UI resolves the same references.
 *
 * @internal
 */
final class SharedDefinitions
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'value' => [
                'description' => 'A literal, {"ref": "<path>"} or {"relative": <duration>}.',
            ],
            'field' => [
                'type' => 'string',
                'format' => 'field-path',
                'maxLength' => 200,
            ],
            'party' => [
                'type' => 'object',
                'required' => ['kind'],
                'properties' => [
                    'kind' => ['enum' => ['user', 'field', 'role', 'triggered_by']],
                    'id' => ['type' => ['integer', 'string'], 'x-workflows-owner-specific' => true],
                    'field' => ['$ref' => '#/$defs/field'],
                    'role' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                ],
                'additionalProperties' => false,
            ],
            'parties' => [
                'type' => 'array',
                'items' => ['$ref' => '#/$defs/party'],
                'minItems' => 1,
                'maxItems' => 50,
            ],
            'duration' => [
                'anyOf' => [
                    ['type' => 'string', 'format' => 'duration'],
                    [
                        'type' => 'object',
                        'required' => ['amount', 'unit'],
                        'properties' => [
                            'amount' => ['type' => 'integer', 'minimum' => -100000, 'maximum' => 100000],
                            'unit' => ['enum' => ['minutes', 'hours', 'days', 'weeks', 'months', 'business_minutes', 'business_hours', 'business_days']],
                            'calendar' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'condition' => [
                'type' => 'object',
                'description' => 'A declarative condition: {"all"|"any": [...]}, {"not": {...}} or {"field", "op", "value"}.',
            ],
        ];
    }
}
