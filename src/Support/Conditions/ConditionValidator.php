<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Conditions;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ConditionOperator;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Decimals;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Values;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Publish-time checks of a condition tree: shape, depth and clause caps, that every field path
 * exists in the subject / owner schema (or is a declared input, a trigger key, an ancestor's
 * output or `now`), that the operator suits the field type, and that literals have that type.
 *
 * @internal
 */
final class ConditionValidator
{
    /**
     * @var array<string, list<ConditionOperator>>
     */
    private const array OPERATORS = [
        'string' => [ConditionOperator::Equals, ConditionOperator::In, ConditionOperator::IsEmpty, ConditionOperator::Contains],
        'text' => [ConditionOperator::Equals, ConditionOperator::In, ConditionOperator::IsEmpty, ConditionOperator::Contains],
        'integer' => [ConditionOperator::Equals, ConditionOperator::In, ConditionOperator::Gt, ConditionOperator::Lt, ConditionOperator::Between, ConditionOperator::IsEmpty],
        'decimal' => [ConditionOperator::Equals, ConditionOperator::In, ConditionOperator::Gt, ConditionOperator::Lt, ConditionOperator::Between, ConditionOperator::IsEmpty],
        'boolean' => [ConditionOperator::Equals, ConditionOperator::IsEmpty],
        'date' => [ConditionOperator::Equals, ConditionOperator::Before, ConditionOperator::After, ConditionOperator::Between, ConditionOperator::IsEmpty],
        'datetime' => [ConditionOperator::Equals, ConditionOperator::Before, ConditionOperator::After, ConditionOperator::Between, ConditionOperator::IsEmpty],
        'enum' => [ConditionOperator::Equals, ConditionOperator::In, ConditionOperator::IsEmpty],
        'party' => [ConditionOperator::Equals, ConditionOperator::In, ConditionOperator::IsEmpty],
        'list' => [ConditionOperator::Contains, ConditionOperator::IsEmpty],
    ];

    /**
     * @return list<ValidationError>
     */
    public static function validate(mixed $condition, ValidationScope $scope, string $path): array
    {
        $errors = [];
        $clauses = 0;

        self::walk($condition, $scope, $path, 1, $clauses, $errors);

        if ($clauses > WorkflowsConfig::conditionsMaxClauses()) {
            $errors[] = ValidationError::make(ValidationCode::ConditionTooComplex, node: $scope->node?->id, path: $path, params: ['limit' => WorkflowsConfig::conditionsMaxClauses(), 'kind' => 'clauses']);
        }

        return $errors;
    }

    /**
     * Validate one field path (used by conditions, set_field, delays and party fields).
     */
    public static function field(string $field, ValidationScope $scope, string $path): ?ValidationError
    {
        $node = $scope->node?->id;

        if ($field === 'now') {
            return null;
        }

        [$root, $rest] = array_pad(explode('.', $field, 2), 2, '');

        return match ($root) {
            'subject' => $scope->subjectType?->field($rest) !== null ? null : ValidationError::make(ValidationCode::UnknownField, node: $node, path: $path, params: ['field' => $field]),
            'owner' => $scope->ownerType?->field($rest) !== null ? null : ValidationError::make(ValidationCode::UnknownField, node: $node, path: $path, params: ['field' => $field]),
            'input' => isset($scope->inputs[$rest]) ? null : ValidationError::make(ValidationCode::UnknownInput, node: $node, path: $path, params: ['field' => $field]),
            'trigger' => $rest !== '' ? null : ValidationError::make(ValidationCode::UnknownField, node: $node, path: $path, params: ['field' => $field]),
            'steps' => self::stepRef($rest, $scope, $path, $field),
            default => ValidationError::make(ValidationCode::UnknownField, node: $node, path: $path, params: ['field' => $field]),
        };
    }

    public static function typeOf(string $field, ValidationScope $scope): ?FieldType
    {
        if ($field === 'now') {
            return FieldType::Datetime;
        }

        [$root, $rest] = array_pad(explode('.', $field, 2), 2, '');

        return match ($root) {
            'subject' => $scope->subjectType?->field($rest)?->type,
            'owner' => $scope->ownerType?->field($rest)?->type,
            'input' => $scope->inputs[$rest] ?? null,
            default => null,
        };
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private static function walk(mixed $condition, ValidationScope $scope, string $path, int $depth, int &$clauses, array &$errors): void
    {
        $node = $scope->node?->id;

        if ($depth > WorkflowsConfig::conditionsMaxDepth()) {
            $errors[] = ValidationError::make(ValidationCode::ConditionTooComplex, node: $node, path: $path, params: ['limit' => WorkflowsConfig::conditionsMaxDepth(), 'kind' => 'depth']);

            return;
        }

        if (! is_array($condition) || $condition === []) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: $path, params: ['keyword' => 'condition', 'expected' => 'object']);

            return;
        }

        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $condition)) {
                if (count($condition) !== 1 || ! is_array($condition[$group]) || ! array_is_list($condition[$group]) || $condition[$group] === []) {
                    $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: $path, params: ['keyword' => $group, 'expected' => 'array']);

                    return;
                }

                foreach ($condition[$group] as $index => $child) {
                    self::walk($child, $scope, $path.'/'.$group.'/'.$index, $depth + 1, $clauses, $errors);
                }

                return;
            }
        }

        if (array_key_exists('not', $condition)) {
            if (count($condition) !== 1) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: $path, params: ['keyword' => 'not', 'expected' => 'object']);

                return;
            }

            self::walk($condition['not'], $scope, $path.'/not', $depth + 1, $clauses, $errors);

            return;
        }

        $clauses++;
        self::clause($condition, $scope, $path, $errors);
    }

    /**
     * @param  array<array-key, mixed>  $clause
     * @param  list<ValidationError>  $errors
     */
    private static function clause(array $clause, ValidationScope $scope, string $path, array &$errors): void
    {
        $node = $scope->node?->id;
        $field = $clause['field'] ?? null;
        $op = is_string($clause['op'] ?? null) ? ConditionOperator::tryFrom($clause['op']) : null;

        $unknown = array_diff(array_keys($clause), ['field', 'op', 'value', 'case']);

        if (! is_string($field) || $op === null || $unknown !== []) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: $path, params: ['keyword' => 'clause', 'expected' => '{field, op, value}']);

            return;
        }

        $fieldError = self::field($field, $scope, $path.'/field');

        if ($fieldError !== null) {
            $errors[] = $fieldError;

            return;
        }

        $type = self::typeOf($field, $scope);

        if ($type !== null && ! in_array($op, self::OPERATORS[$type->value], true)) {
            $errors[] = ValidationError::make(ValidationCode::OperatorNotAllowed, node: $node, path: $path.'/op', params: ['field' => $field, 'op' => $op->value, 'type' => $type->value]);

            return;
        }

        if ($op === ConditionOperator::IsEmpty) {
            return;
        }

        if (! array_key_exists('value', $clause)) {
            $errors[] = ValidationError::make(ValidationCode::InvalidValue, node: $node, path: $path.'/value', params: ['field' => $field]);

            return;
        }

        $value = $clause['value'];

        if ($op === ConditionOperator::In || $op === ConditionOperator::Between) {
            if (Values::isRef($value)) {
                return;
            }

            if (! is_array($value) || ! array_is_list($value) || ($op === ConditionOperator::Between && count($value) !== 2) || $value === []) {
                $errors[] = ValidationError::make(ValidationCode::InvalidValue, node: $node, path: $path.'/value', params: ['field' => $field]);

                return;
            }

            foreach ($value as $index => $item) {
                self::literal($item, $type, $scope, $path.'/value/'.$index, $field, $errors);
            }

            return;
        }

        self::literal($value, $type, $scope, $path.'/value', $field, $errors);
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private static function literal(mixed $value, ?FieldType $type, ValidationScope $scope, string $path, string $field, array &$errors): void
    {
        $node = $scope->node?->id;

        if (Values::isRef($value)) {
            /** @var array{ref: string} $value */
            $refError = self::field($value['ref'], $scope, $path.'/ref');

            if ($refError !== null) {
                $errors[] = $refError;
            }

            return;
        }

        if (Values::isRelative($value)) {
            if (($type !== FieldType::Date && $type !== FieldType::Datetime) || Durations::parse(is_array($value) ? $value['relative'] : null) === null) {
                $errors[] = ValidationError::make(ValidationCode::InvalidValue, node: $node, path: $path, params: ['field' => $field]);
            }

            return;
        }

        $valid = match ($type) {
            null => is_scalar($value) || $value === null || (is_array($value) && ($value['kind'] ?? null) === 'triggered_by'),
            FieldType::Integer, FieldType::Decimal => Decimals::isDecimal($value),
            FieldType::Boolean => is_bool($value),
            FieldType::Date, FieldType::Datetime => is_string($value) && strtotime($value) !== false,
            FieldType::Party => is_int($value) || is_string($value) || (is_array($value) && ($value['kind'] ?? null) === 'triggered_by'),
            FieldType::Enum => is_string($value) || is_int($value),
            default => is_scalar($value),
        };

        if ($valid && $type === FieldType::Enum) {
            $options = ConditionValidator::enumOptions($field, $scope);
            $valid = $options === [] || in_array($value, $options, true);
        }

        if (! $valid) {
            $errors[] = ValidationError::make(ValidationCode::InvalidValue, node: $node, path: $path, params: ['field' => $field]);
        }
    }

    /**
     * @return list<string|int>
     */
    private static function enumOptions(string $field, ValidationScope $scope): array
    {
        [$root, $rest] = array_pad(explode('.', $field, 2), 2, '');

        return match ($root) {
            'subject' => $scope->subjectType?->field($rest)->options ?? [],
            'owner' => $scope->ownerType?->field($rest)->options ?? [],
            default => [],
        };
    }

    private static function stepRef(string $rest, ValidationScope $scope, string $path, string $field): ?ValidationError
    {
        $parts = explode('.', $rest, 3);
        $node = $scope->node?->id;

        if (count($parts) < 2 || $parts[1] !== 'output' || $scope->graph->node($parts[0]) === null || ! $scope->isAncestor($parts[0])) {
            return ValidationError::make(ValidationCode::InvalidReference, node: $node, path: $path, params: ['field' => $field]);
        }

        return null;
    }
}
