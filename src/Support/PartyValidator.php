<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;

/**
 * Publish checks of party lists: `field` parties name a registered party field, `role` parties
 * need a role resolver (teams or the host's).
 *
 * @internal
 */
final class PartyValidator
{
    /**
     * @return list<ValidationError>
     */
    public static function validate(mixed $parties, ValidationScope $scope, string $path): array
    {
        $errors = [];
        $node = $scope->node?->id;

        if (! is_array($parties) || ! array_is_list($parties) || $parties === []) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: $path, params: ['keyword' => 'minItems', 'expected' => '1'])];
        }

        foreach ($parties as $index => $party) {
            if (! is_array($party)) {
                continue;
            }

            $kind = $party['kind'] ?? null;

            if ($kind === 'field' && is_string($party['field'] ?? null)) {
                $error = ConditionValidator::field($party['field'], $scope, $path.'/'.$index.'/field');

                if ($error !== null) {
                    $errors[] = $error;
                } elseif (ConditionValidator::typeOf($party['field'], $scope) !== FieldType::Party) {
                    $errors[] = ValidationError::make(ValidationCode::InvalidValue, node: $node, path: $path.'/'.$index.'/field', params: ['field' => $party['field']]);
                }
            }

            if ($kind === 'role' && Adapters::roles() === null) {
                $errors[] = ValidationError::make(ValidationCode::RolesUnavailable, node: $node, path: $path.'/'.$index, params: ['role' => is_string($party['role'] ?? null) ? $party['role'] : '']);
            }
        }

        return $errors;
    }
}
