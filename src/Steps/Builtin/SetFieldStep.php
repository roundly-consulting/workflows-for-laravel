<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;
use RoundlyConsulting\Workflows\Support\Decimals;
use RoundlyConsulting\Workflows\Support\FieldReader;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\Read;
use RoundlyConsulting\Workflows\Support\Values;

/**
 * `{field: "subject.<field>", value}`: writes a writable registered attribute of the subject.
 * Relation paths and lifecycle state columns are refused at publish; the subject type's ability
 * (default `update`) is checked first.
 *
 * @internal
 */
final class SetFieldStep extends BuiltinStep
{
    public function key(): string
    {
        return 'set_field';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function transactional(): bool
    {
        return true;
    }

    public function abilityFor(StepContext $context): string
    {
        return $context->scope()->subjectType->ability ?? 'update';
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['field', 'value'],
            'additionalProperties' => false,
            'properties' => [
                'field' => ['$ref' => '#/$defs/field'],
                'value' => ['$ref' => '#/$defs/value'],
            ],
        ];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $node = $scope->node?->id;
        $field = is_string($config['field'] ?? null) ? $config['field'] : '';

        if (! str_starts_with($field, 'subject.')) {
            return [ValidationError::make(ValidationCode::FieldNotWritable, node: $node, path: '/field', params: ['field' => $field])];
        }

        $definition = $scope->subjectType?->field(substr($field, 8));

        if ($definition === null) {
            return [ValidationError::make(ValidationCode::UnknownField, node: $node, path: '/field', params: ['field' => $field])];
        }

        if (! $definition->writable || $definition->isRelationPath() || $this->isLifecycleColumn($scope, $definition)) {
            return [ValidationError::make(ValidationCode::FieldNotWritable, node: $node, path: '/field', params: ['field' => $field])];
        }

        $value = $config['value'] ?? null;

        if (Values::isRef($value)) {
            /** @var array{ref: string} $value */
            $error = ConditionValidator::field($value['ref'], $scope, '/value/ref');

            return $error === null ? [] : [$error];
        }

        return self::normalize($value, $definition) === false
            ? [ValidationError::make(ValidationCode::InvalidValue, node: $node, path: '/value', params: ['field' => $field])]
            : [];
    }

    public function handle(StepContext $context): StepResult
    {
        $subject = $context->subject();
        $type = $context->scope()->subjectType;
        $field = Read::string($context->config(), 'field');
        $definition = $type?->field(substr($field, 8));

        if ($subject === null || $definition === null) {
            throw StepFailedException::because('subject_missing', Messages::get('failures.subject_missing'));
        }

        $value = self::normalize($context->resolve($context->config()['value'] ?? null), $definition);

        if ($value === false) {
            throw StepFailedException::because('invalid_value', Messages::get('failures.invalid_value', ['field' => $field]));
        }

        $subject->forceFill([$definition->path() => $value])->save();

        return StepResult::complete(output: ['field' => $field, 'value' => is_scalar($value) || $value === null ? $value : null]);
    }

    public function simulate(StepContext $context): StepResult
    {
        $field = Read::string($context->config(), 'field');

        return StepResult::complete(output: ['field' => $field])->wouldDo('set '.$field, ['field' => $field]);
    }

    /**
     * The value to store, or false when it does not fit the field type.
     */
    private static function normalize(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null) {
            return null;
        }

        $normalized = match ($field->type) {
            FieldType::Decimal => Decimals::normalize($value) ?? false,
            FieldType::Integer => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : false,
            FieldType::Boolean => is_bool($value) ? $value : false,
            FieldType::Date, FieldType::Datetime => FieldReader::date($value, 'UTC') ?? false,
            FieldType::Enum => (is_string($value) || is_int($value)) && ($field->options === [] || in_array($value, $field->options, true)) ? $value : false,
            FieldType::Party, FieldType::List => false,
            default => is_scalar($value) ? (string) $value : false,
        };

        return $normalized;
    }

    private function isLifecycleColumn(ValidationScope $scope, FieldDefinition $definition): bool
    {
        $adapter = Adapters::transition();

        return $scope->subjectType !== null && $adapter !== null && in_array($definition->path(), $adapter->stateAttributes($scope->subjectType->model), true);
    }
}
