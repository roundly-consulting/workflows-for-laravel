<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Conditions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\ConditionOperator;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Support\Decimals;
use RoundlyConsulting\Workflows\Support\FieldReader;
use RoundlyConsulting\Workflows\Support\Values;
use RoundlyConsulting\Workflows\Support\ValueScope;

/**
 * The fixed interpreter of declarative conditions: `all` / `any` / `not` groups over clauses
 * `{field, op, value}`. It executes no code: no eval, no user regex, no method calls, and it
 * reads only registered fields. Null compares false under every operator except `empty`.
 *
 * @internal
 */
final class Evaluator
{
    /**
     * @param  array<array-key, mixed>  $condition
     */
    public static function evaluate(array $condition, ValueScope $scope): bool
    {
        if (array_key_exists('all', $condition)) {
            foreach (self::children($condition['all']) as $child) {
                if (! self::evaluate($child, $scope)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $condition)) {
            foreach (self::children($condition['any']) as $child) {
                if (self::evaluate($child, $scope)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $condition)) {
            return is_array($condition['not']) && ! self::evaluate($condition['not'], $scope);
        }

        return self::clause($condition, $scope);
    }

    /**
     * @param  array<array-key, mixed>  $clause
     */
    private static function clause(array $clause, ValueScope $scope): bool
    {
        $field = $clause['field'] ?? null;
        $operator = is_string($clause['op'] ?? null) ? ConditionOperator::tryFrom($clause['op']) : null;

        if (! is_string($field) || $operator === null) {
            return false;
        }

        $left = Values::path($field, $scope);
        $type = Values::typeOf($field, $scope) ?? self::infer($left);

        if ($operator === ConditionOperator::IsEmpty) {
            return $left === null || $left === '' || $left === [];
        }

        if ($left === null) {
            return false;
        }

        $insensitive = ($clause['case'] ?? null) === 'insensitive';
        $value = $clause['value'] ?? null;

        return match ($operator) {
            ConditionOperator::Equals => self::compare($left, Values::resolve($value, $scope), $type, $scope) === 0,
            ConditionOperator::In => self::in($left, $value, $type, $scope),
            ConditionOperator::Gt => self::compare($left, Values::resolve($value, $scope), $type, $scope) === 1,
            ConditionOperator::Lt => self::compare($left, Values::resolve($value, $scope), $type, $scope) === -1,
            ConditionOperator::Before => self::isDateType($type) && self::compare($left, Values::resolve($value, $scope), $type, $scope) === -1,
            ConditionOperator::After => self::isDateType($type) && self::compare($left, Values::resolve($value, $scope), $type, $scope) === 1,
            ConditionOperator::Between => self::between($left, $value, $type, $scope),
            default => self::contains($left, Values::resolve($value, $scope), $insensitive),
        };
    }

    /**
     * -1 / 0 / 1, or null when the two sides are not comparable.
     */
    public static function compare(mixed $left, mixed $right, ?FieldType $type, ValueScope $scope): ?int
    {
        if ($right === null) {
            return null;
        }

        return match ($type) {
            FieldType::Party => self::sameParty($left, $right, $scope) ? 0 : null,
            FieldType::Date => self::compareDates($left, $right, $scope, dateOnly: true),
            FieldType::Datetime => self::compareDates($left, $right, $scope, dateOnly: false),
            FieldType::Decimal, FieldType::Integer => Decimals::compare($left, $right),
            FieldType::Boolean => is_bool($right) && $left === $right ? 0 : null,
            default => self::compareScalars($left, $right),
        };
    }

    private static function in(mixed $left, mixed $value, ?FieldType $type, ValueScope $scope): bool
    {
        $values = Values::resolve($value, $scope);

        if (! is_array($values)) {
            return false;
        }

        foreach ($values as $candidate) {
            if (self::compare($left, Values::resolve($candidate, $scope), $type, $scope) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function between(mixed $left, mixed $value, ?FieldType $type, ValueScope $scope): bool
    {
        if (! is_array($value) || count($value) !== 2 || ! array_is_list($value)) {
            return false;
        }

        $low = self::compare($left, Values::resolve($value[0], $scope), $type, $scope);
        $high = self::compare($left, Values::resolve($value[1], $scope), $type, $scope);

        return $low !== null && $high !== null && $low >= 0 && $high <= 0;
    }

    private static function contains(mixed $left, mixed $right, bool $insensitive): bool
    {
        if (is_array($left)) {
            foreach ($left as $item) {
                if (is_scalar($item) && is_scalar($right) && (string) $item === (string) $right) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($left) || ! is_scalar($right) || (string) $right === '') {
            return false;
        }

        return $insensitive
            ? mb_stripos($left, (string) $right) !== false
            : str_contains($left, (string) $right);
    }

    private static function sameParty(mixed $left, mixed $right, ValueScope $scope): bool
    {
        if (! $left instanceof Model) {
            return false;
        }

        if (is_array($right) && ($right['kind'] ?? null) === 'triggered_by') {
            $right = $scope->starter;
        }

        if ($right instanceof Model) {
            return $left->is($right);
        }

        $key = $left->getKey();

        return is_scalar($right) && is_scalar($key) && (string) $key === (string) $right;
    }

    private static function compareDates(mixed $left, mixed $right, ValueScope $scope, bool $dateOnly): ?int
    {
        $a = $left instanceof CarbonInterface ? CarbonImmutable::instance($left)->setTimezone($scope->timezone) : FieldReader::date($left, $scope->timezone);
        $b = $right instanceof CarbonInterface ? CarbonImmutable::instance($right)->setTimezone($scope->timezone) : self::parseDate($right, $scope->timezone);

        if ($a === null || $b === null) {
            return null;
        }

        if ($dateOnly) {
            return strcmp($a->format('Y-m-d'), $b->format('Y-m-d')) <=> 0;
        }

        return $a->getTimestamp() <=> $b->getTimestamp();
    }

    private static function parseDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // A plain date literal is a calendar date in the version timezone.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $date instanceof CarbonImmutable ? $date : null;
        }

        return FieldReader::date($value, $timezone);
    }

    private static function compareScalars(mixed $left, mixed $right): ?int
    {
        if (Decimals::isDecimal($left) && Decimals::isDecimal($right)) {
            return Decimals::compare($left, $right);
        }

        if (is_bool($left) || is_bool($right)) {
            return $left === $right ? 0 : null;
        }

        if (! is_scalar($left) || ! is_scalar($right)) {
            return null;
        }

        return strcmp((string) $left, (string) $right) <=> 0;
    }

    private static function infer(mixed $value): ?FieldType
    {
        return match (true) {
            $value instanceof Model => FieldType::Party,
            $value instanceof CarbonInterface => FieldType::Datetime,
            is_bool($value) => FieldType::Boolean,
            is_array($value) => FieldType::List,
            default => null,
        };
    }

    private static function isDateType(?FieldType $type): bool
    {
        return $type === FieldType::Date || $type === FieldType::Datetime;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function children(mixed $children): array
    {
        if (! is_array($children)) {
            return [];
        }

        return array_values(array_filter($children, is_array(...)));
    }
}
