<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;
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

            $options = is_array($definition['options'] ?? null) && $definition['options'] !== [];

            $typeRule = match (true) {
                $type === FieldType::Integer => 'integer',
                $type === FieldType::Decimal => 'numeric',
                $type === FieldType::Boolean => 'boolean',
                $type === FieldType::Date, $type === FieldType::Datetime => 'date',
                $type === FieldType::List => 'array',
                // A person: a user's key (or the user), resolved to the user when it is read.
                $type === FieldType::Party => self::partyRule(...),
                // Options of any scalar type: `in` alone decides.
                $type === FieldType::Enum && $options => null,
                default => 'string',
            };

            if ($typeRule !== null) {
                $fieldRules[] = $typeRule;
            }

            if ($options) {
                // A rule object, never an `in:` string: Laravel splits that on commas.
                $fieldRules[] = Rule::in(array_values(array_filter($definition['options'], is_scalar(...))));
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

        $validated = GraphParser::stringKeyed($validator->validated());

        foreach ($validated as $name => $value) {
            if ($value instanceof Model) {
                $validated[$name] = $value->getKey();
            }
        }

        return $validated;
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    private static function partyRule(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $value instanceof Model ? $value : PartyResolver::userById($value);
        $class = WorkflowsConfig::partiesUserModel();

        if ($user === null || $class === null || ! $user instanceof $class) {
            $fail('validation.exists')->translate();
        }
    }
}
