<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Validation\Factory;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Graph\GraphParser;

/**
 * Manual-trigger input fields: their declared types (for `input.*` refs) and their validation
 * through Laravel's validator, with rules derived from type / required / options plus an
 * allow-listed set of extras (`max:`, `min:`, `email`, `url`; `regex` is refused).
 *
 * @internal
 */
final class TriggerInputs
{
    public static function ruleAllowed(string $rule): bool
    {
        return preg_match('/^(max:\d{1,9}|min:\d{1,9}|email|url)$/', $rule) === 1;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @return array<string, FieldType>
     */
    public static function declared(array $trigger): array
    {
        $inputs = [];

        foreach (is_array($trigger['inputs'] ?? null) ? $trigger['inputs'] : [] as $input) {
            if (! is_array($input) || ! is_string($input['name'] ?? null)) {
                continue;
            }

            $type = is_string($input['type'] ?? null) ? FieldType::tryFrom($input['type']) : null;
            $inputs[$input['name']] = $type ?? FieldType::String;
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws InvalidTriggerInputException
     */
    public static function validate(array $trigger, array $input): array
    {
        $definitions = is_array($trigger['inputs'] ?? null) ? $trigger['inputs'] : [];

        if ($definitions === []) {
            return $input;
        }

        $rules = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['name'] ?? null)) {
                continue;
            }

            $type = is_string($definition['type'] ?? null) ? FieldType::tryFrom($definition['type']) : FieldType::String;
            $fieldRules = [($definition['required'] ?? false) === true ? 'required' : 'nullable'];

            $fieldRules[] = match ($type) {
                FieldType::Integer => 'integer',
                FieldType::Decimal => 'numeric',
                FieldType::Boolean => 'boolean',
                FieldType::Date, FieldType::Datetime => 'date',
                FieldType::List => 'array',
                default => 'string',
            };

            if (is_array($definition['options'] ?? null) && $definition['options'] !== []) {
                $fieldRules[] = 'in:'.implode(',', array_map(static fn (mixed $option): string => is_scalar($option) ? (string) $option : '', $definition['options']));
            }

            foreach (is_array($definition['rules'] ?? null) ? $definition['rules'] : [] as $rule) {
                if (is_string($rule) && self::ruleAllowed($rule)) {
                    $fieldRules[] = $rule;
                }
            }

            $rules[$definition['name']] = $fieldRules;
        }

        $validator = app(Factory::class)->make($input, $rules);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            throw InvalidTriggerInputException::withErrors($errors);
        }

        return GraphParser::stringKeyed($validator->validated());
    }
}
