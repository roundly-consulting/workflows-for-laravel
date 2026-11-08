<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\ValueScope;

// X-2 (chat review round): the condition interpreter's edges, one behaviour per case — groups,
// malformed clauses, bounds, list and string matching, party, date and scalar comparison, and the
// type a value without a declared field type is compared as.
mutates(Evaluator::class);

/**
 * @param  array<string, mixed>  $input
 * @param  array<string, FieldType>  $types
 * @param  array<string, mixed>  $trigger
 */
function scopeOf(array $input = [], array $types = [], array $trigger = [], string $timezone = 'UTC', ?Model $starter = null): ValueScope
{
    return new ValueScope(
        subject: null,
        subjectType: null,
        owner: null,
        ownerType: null,
        input: $input,
        trigger: $trigger,
        outputs: static fn (string $node): ?array => null,
        now: CarbonImmutable::parse('2026-11-09 10:00', 'UTC'),
        timezone: $timezone,
        starter: $starter,
        inputTypes: $types,
    );
}

/**
 * @param  array<array-key, mixed>  $condition
 * @param  array<string, mixed>  $input
 * @param  array<string, FieldType>  $types
 */
function evaluates(array $condition, array $input = [], array $types = [], string $timezone = 'UTC'): bool
{
    return Evaluator::evaluate($condition, scopeOf($input, $types, timezone: $timezone));
}

/**
 * @return array{field: string, op: string, value?: mixed}
 */
function on(string $field, string $op, mixed $value = null): array
{
    return func_num_args() === 3 ? ['field' => $field, 'op' => $op, 'value' => $value] : ['field' => $field, 'op' => $op];
}

it('needs every child of all and one child of any', function (array $condition, bool $expected): void {
    expect(evaluates($condition, ['n' => 5], ['n' => FieldType::Integer]))->toBe($expected);
})->with([
    'all, one false' => [['all' => [on('input.n', 'gt', 1), on('input.n', 'gt', 9)]], false],
    'all, every true' => [['all' => [on('input.n', 'gt', 1), on('input.n', 'lt', 9)]], true],
    'any, one true' => [['any' => [on('input.n', 'gt', 1)]], true],
    'any, first false' => [['any' => [on('input.n', 'gt', 9), on('input.n', 'gt', 1)]], true],
    'any, none true' => [['any' => [on('input.n', 'gt', 9)]], false],
    // A group decides alone: clause keys next to it are not read.
    'any next to a clause' => [['any' => [on('input.n', 'gt', 9)], 'field' => 'input.n', 'op' => 'equals', 'value' => 5], false],
]);

it('is false for a clause it cannot read, without throwing', function (array $clause): void {
    expect(evaluates($clause, ['s' => 'abc', 'amount' => null], ['amount' => FieldType::Decimal]))->toBeFalse();
})->with([
    'a field that is not a string' => [['field' => 5, 'op' => 'equals', 'value' => 5]],
    'an unknown operator' => [['field' => 'input.s', 'op' => 'resembles', 'value' => 'abc']],
    'a null decimal against zero' => [on('input.amount', 'equals', 0)],
]);

it('compares numbers by the declared field type', function (array $clause, bool $expected): void {
    expect(evaluates($clause, ['n' => 5], ['n' => FieldType::Integer]))->toBe($expected);
})->with([
    'lt holds' => [on('input.n', 'lt', 9), true],
    'lt fails' => [on('input.n', 'lt', 5), false],
    'in, only a greater candidate' => [on('input.n', 'in', [3]), false],
    'in, a match' => [on('input.n', 'in', [3, 5]), true],
    'between, inside' => [on('input.n', 'between', [1, 9]), true],
    'between, the upper bound' => [on('input.n', 'between', [1, 5]), true],
    'between, the lower bound' => [on('input.n', 'between', [5, 9]), true],
    'between, below' => [on('input.n', 'between', [6, 9]), false],
    'between, above' => [on('input.n', 'between', [1, 4]), false],
    'between, an unreadable lower bound' => [on('input.n', 'between', ['abc', 9]), false],
    'between, an unreadable upper bound' => [on('input.n', 'between', [1, 'abc']), false],
    'gt a missing ref' => [on('input.n', 'gt', ['ref' => 'input.missing']), false],
]);

it('returns null for values it cannot compare', function (mixed $left, mixed $right, ?FieldType $type): void {
    expect(Evaluator::compare($left, $right, $type, scopeOf()))->toBeNull();
})->with([
    'no right side' => [5, null, FieldType::Integer],
    'a party that is not a model' => [5, 5, FieldType::Party],
    'a boolean and a string' => [true, '1', null],
    'two different booleans' => [true, false, null],
    'an array' => [[1], 'a', null],
]);

it('compares scalars without a declared type', function (mixed $left, mixed $right, int $expected): void {
    expect(Evaluator::compare($left, $right, null, scopeOf()))->toBe($expected);
})->with([
    'decimals as numbers' => ['10', '9', 1],
    'decimals, smaller' => ['9', '10', -1],
    'a word and a number as text' => ['abc', '5', 1],
    'a number and a word as text' => [5, 'abc', -1],
    'a word and an integer as text' => ['abc', 5, 1],
    'equal booleans' => [true, true, 0],
]);

it('matches contains on lists and strings', function (mixed $left, mixed $right, bool $insensitive, bool $expected): void {
    $clause = ['field' => 'trigger.value', 'op' => 'contains', 'value' => $right, ...($insensitive ? ['case' => 'insensitive'] : [])];

    expect(Evaluator::evaluate($clause, scopeOf(trigger: ['value' => $left])))->toBe($expected);
})->with([
    'a list holding the integer' => [[1, 2], 2, false, true],
    'a list holding the string of it' => [[1, 2], '2', false, true],
    'a list without it' => [[1, 2], 3, false, false],
    'a null item never matches' => [[null], '', false, false],
    'a string holding an integer' => ['Room 101', 101, false, true],
    'insensitive, holding an integer' => ['Room 101', 1, true, true],
    'insensitive, a miss' => ['Room 101', 'hall', true, false],
    'an empty needle' => ['Room', '', false, false],
    'a false needle' => ['Room', false, false, false],
    'a number is not a string' => [123, '2', false, false],
]);

it('compares parties by model or key', function (): void {
    $ada = user('Ada');
    $eve = user('Eve');
    $scope = scopeOf(starter: $ada);

    expect(Evaluator::compare($ada, $ada->id, FieldType::Party, $scope))->toBe(0)
        ->and(Evaluator::compare($ada, (string) $ada->id, FieldType::Party, $scope))->toBe(0)
        ->and(Evaluator::compare($ada, $eve->id, FieldType::Party, $scope))->toBeNull()
        ->and(Evaluator::compare($ada, ['kind' => 'triggered_by'], FieldType::Party, $scope))->toBe(0)
        ->and(Evaluator::compare($eve, ['kind' => 'triggered_by'], FieldType::Party, $scope))->toBeNull()
        // Only `triggered_by` reads the starter; another party kind is not this comparison.
        ->and(Evaluator::compare($ada, ['kind' => 'role', 'role' => 'legal'], FieldType::Party, $scope))->toBeNull();
});

it('compares dates as calendar days and datetimes as instants', function (array $clause, mixed $value, FieldType $type, string $timezone, bool $expected): void {
    expect(evaluates($clause, ['at' => $value], ['at' => $type], $timezone))->toBe($expected);
})->with([
    'datetime, same day, earlier time' => [on('input.at', 'equals', '2026-11-09 18:00'), '2026-11-09 10:00', FieldType::Datetime, 'UTC', false],
    'datetime before' => [on('input.at', 'before', '2026-11-09 18:00'), '2026-11-09 10:00', FieldType::Datetime, 'UTC', true],
    'datetime not after' => [on('input.at', 'after', '2026-11-09 18:00'), '2026-11-09 10:00', FieldType::Datetime, 'UTC', false],
    'datetime after' => [on('input.at', 'after', '2026-11-09 08:00'), '2026-11-09 10:00', FieldType::Datetime, 'UTC', true],
    'a date literal is local midnight' => [on('input.at', 'after', '2026-11-10'), CarbonImmutable::parse('2026-11-09 23:30', 'UTC'), FieldType::Datetime, 'Europe/Bratislava', true],
    'a raw date equals its day' => [on('input.at', 'equals', '2026-11-09'), '2026-11-09', FieldType::Date, 'UTC', true],
    'a date before' => [on('input.at', 'before', '2026-11-10'), '2026-11-09', FieldType::Date, 'UTC', true],
    'a date instant shifts to the timezone' => [on('input.at', 'equals', '2026-11-09'), CarbonImmutable::parse('2026-11-10 00:00', 'UTC'), FieldType::Date, 'America/New_York', true],
    'a date against a number' => [on('input.at', 'equals', 5), '2026-11-09', FieldType::Date, 'UTC', false],
]);

it('infers the type of a value without a declared field', function (string $op, mixed $left, mixed $right, bool $expected): void {
    expect(Evaluator::evaluate(on('trigger.value', $op, $right), scopeOf(trigger: ['value' => $left])))->toBe($expected);
})->with([
    'a string compares as text' => ['equals', 'abc', 'abc', true],
    'an instant compares as a datetime' => ['before', CarbonImmutable::parse('2026-11-09 10:00', 'UTC'), '2026-11-10', true],
]);

it('infers a party from a model value', function (): void {
    $ada = user('Ada');

    expect(Evaluator::evaluate(on('trigger.who', 'equals', $ada->id), scopeOf(trigger: ['who' => $ada])))->toBeTrue();
});
