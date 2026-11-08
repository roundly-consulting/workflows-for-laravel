<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\Decimals;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\ValueScope;

// W-27: `composer mutate` mutates the pure core these tests pin.
mutates(Evaluator::class, Decimals::class);

/**
 * @param  array<string, mixed>  $condition
 * @param  array<string, mixed>  $input
 */
function holds(array $condition, ?Model $subject = null, array $input = [], string $timezone = 'UTC', ?Model $owner = null, ?CarbonImmutable $now = null, ?Model $starter = null): bool
{
    $registry = app(SubjectRegistry::class);

    return Evaluator::evaluate($condition, new ValueScope(
        subject: $subject,
        subjectType: $registry->find('document'),
        owner: $owner,
        ownerType: $owner === null ? null : $registry->forModel($owner),
        input: $input,
        trigger: ['plan' => 'gold'],
        outputs: static fn (string $node): ?array => $node === 'task' ? ['task_id' => 7] : null,
        now: $now ?? CarbonImmutable::parse('2026-11-09 10:00', 'UTC'),
        timezone: $timezone,
        starter: $starter,
        inputTypes: ['urgent' => FieldType::Boolean, 'note' => FieldType::String],
    ));
}

/**
 * @param  array<string, mixed>  $condition
 */
function clause(string $field, string $op, mixed $value = null): array
{
    return func_num_args() === 3 ? ['field' => $field, 'op' => $op, 'value' => $value] : ['field' => $field, 'op' => $op];
}

it('evaluates every operator against registered fields', function (array $condition, bool $expected): void {
    $requester = user('Rita');
    $document = document(company(), [
        'amount' => '60000.00', 'priority' => 'high', 'department' => 'legal', 'urgent' => true,
        'due_on' => '2026-11-12 08:00:00', 'requester_id' => $requester->id, 'title' => 'Quarterly Report',
    ]);

    expect(holds($condition, $document, ['urgent' => true, 'note' => 'hello'], starter: $requester))->toBe($expected);
})->with([
    'gt' => [clause('subject.amount', 'gt', '50000'), true],
    'gt int literal' => [clause('subject.amount', 'gt', 50000), true],
    'lt' => [clause('subject.amount', 'lt', '50000'), false],
    'equals decimal' => [clause('subject.amount', 'equals', 60000), true],
    'between inclusive' => [clause('subject.amount', 'between', ['60000', '70000']), true],
    'between outside' => [clause('subject.amount', 'between', ['1', '2']), false],
    'in' => [clause('subject.department', 'in', ['legal', 'finance']), true],
    'not in' => [clause('subject.department', 'in', ['sales']), false],
    'equals string' => [clause('subject.priority', 'equals', 'high'), true],
    'contains' => [clause('subject.title', 'contains', 'Report'), true],
    'contains is case-sensitive' => [clause('subject.title', 'contains', 'report'), false],
    'contains insensitive' => [['field' => 'subject.title', 'op' => 'contains', 'value' => 'report', 'case' => 'insensitive'], true],
    'boolean' => [clause('subject.urgent', 'equals', true), true],
    'input' => [clause('input.urgent', 'equals', true), true],
    'input contains' => [clause('input.note', 'contains', 'ell'), true],
    'trigger ref' => [clause('trigger.plan', 'equals', 'gold'), true],
    'ref value' => [clause('subject.priority', 'equals', ['ref' => 'trigger.plan']), false],
    'step output' => [clause('steps.task.output.task_id', 'equals', 7), true],
    'date before relative' => [clause('subject.due_on', 'before', ['relative' => 'P7D']), true],
    'date after literal' => [clause('subject.due_on', 'after', '2026-11-11'), true],
    'date equals' => [clause('subject.due_on', 'equals', '2026-11-12'), true],
    'party equals triggered_by' => [clause('subject.requester', 'equals', ['kind' => 'triggered_by']), true],
    'party empty' => [clause('subject.approver', 'empty'), true],
    'all' => [['all' => [clause('subject.amount', 'gt', '50000'), clause('subject.department', 'equals', 'legal')]], true],
    'any' => [['any' => [clause('subject.amount', 'lt', '1'), clause('subject.department', 'equals', 'legal')]], true],
    'not' => [['not' => clause('subject.department', 'equals', 'legal')], false],
    'nested' => [['all' => [['any' => [clause('input.urgent', 'equals', false), ['not' => clause('subject.priority', 'equals', 'low')]]]]], true],
    'unknown op' => [['field' => 'subject.amount', 'op' => 'matches', 'value' => '.*'], false],
]);

// C-19: null compares false under every operator except empty.
it('compares null as false except for empty', function (string $op, mixed $value, bool $expected): void {
    expect(holds(clause('subject.amount', $op, $value), document(null, ['amount' => null])))->toBe($expected);
})->with([
    ['equals', '1', false], ['gt', '1', false], ['lt', '1', false], ['in', ['1'], false],
    ['between', ['0', '9'], false], ['contains', '1', false], ['empty', null, true],
]);

it('treats empty strings and lists as empty', function (): void {
    expect(holds(clause('subject.priority', 'empty'), document(null, ['priority' => ''])))->toBeTrue()
        ->and(holds(clause('input.note', 'empty'), null, ['note' => []]))->toBeTrue();
});

// C-17
it('compares decimals exactly and refuses exponents', function (): void {
    $document = document(null, ['amount' => '50000.00']);

    expect(holds(clause('subject.amount', 'equals', '50000'), $document))->toBeTrue()
        ->and(holds(clause('subject.amount', 'equals', '1e5'), $document))->toBeFalse()
        ->and(holds(clause('subject.amount', 'gt', '49999.999'), $document))->toBeTrue();
});

// C-18: a date field compares by calendar date in the version timezone.
it('compares dates in the version timezone', function (): void {
    // 23:30 UTC on Nov 11 is already Nov 12 in Bratislava.
    $document = document(null, ['due_on' => '2026-11-11 23:30:00']);

    expect(holds(clause('subject.due_on', 'equals', '2026-11-11'), $document, timezone: 'UTC'))->toBeTrue()
        ->and(holds(clause('subject.due_on', 'equals', '2026-11-12'), $document, timezone: 'Europe/Bratislava'))->toBeTrue()
        ->and(holds(clause('subject.due_on', 'before', '2026-11-12'), $document, timezone: 'Europe/Bratislava'))->toBeFalse()
        ->and(holds(clause('subject.due_on', 'after', '2026-11-11'), $document, timezone: 'Europe/Bratislava'))->toBeTrue();
});

// C-09: an attribute that exists on the model but is not registered is never read.
it('never reads an unregistered attribute', function (): void {
    $document = document(null, ['secret' => 'top']);
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('check', 'condition', ['when' => clause('subject.secret', 'equals', 'top')]),
        node('done', 'end'),
    ], [['start', 'check'], ['check', 'done', 'true'], ['check', 'done', 'false']]), 'document');

    expect(holds(clause('subject.secret', 'equals', 'top'), $document))->toBeFalse()
        ->and($report->has(ValidationCode::UnknownField, 'check'))->toBeTrue();
});

// C-10: owner.<field> validates against a registered owner type.
it('validates owner fields against the owner schema', function (): void {
    $company = company();
    $company->plan = 'gold';
    $company->save();
    $check = static fn (string $field): array => graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('check', 'condition', ['when' => clause($field, 'equals', 'gold')]),
        node('done', 'end'),
    ], [['start', 'check'], ['check', 'done', 'true'], ['check', 'done', 'false']]);

    expect(Workflows::versions()->validate($check('owner.plan'), 'document', $company)->valid())->toBeTrue()
        ->and(Workflows::versions()->validate($check('owner.nope'), 'document', $company)->has(ValidationCode::UnknownField))->toBeTrue()
        ->and(Workflows::versions()->validate($check('owner.plan'), 'document')->has(ValidationCode::UnknownField))->toBeTrue()
        ->and(holds(clause('owner.plan', 'equals', 'gold'), document($company), owner: $company))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $when
 */
function conditionErrors(array $when, array $inputs = []): array
{
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => $inputs]),
        node('task', 'acme.create_task', ['title' => 'x']),
        node('check', 'condition', ['when' => $when]),
        node('done', 'end'),
    ], [['start', 'task'], ['task', 'check'], ['check', 'done', 'true'], ['check', 'done', 'false']]), 'document');

    return array_map(static fn ($error): string => $error->code->value, $report->errors);
}

it('validates condition clauses', function (array $when, array $codes): void {
    expect(conditionErrors($when, [['name' => 'urgent', 'type' => 'boolean']]))->toBe($codes);
})->with([
    'valid' => [clause('subject.amount', 'gt', '1'), []],
    'operator not allowed' => [clause('subject.amount', 'contains', '1'), ['operator_not_allowed']],
    'bool op' => [clause('subject.urgent', 'gt', true), ['operator_not_allowed']],
    'wrong literal' => [clause('subject.amount', 'gt', 'many'), ['invalid_value']],
    'bool literal' => [clause('subject.urgent', 'equals', 'yes'), ['invalid_value']],
    'enum option' => [clause('subject.department', 'equals', 'it'), ['invalid_value']],
    'date literal' => [clause('subject.due_on', 'before', 'someday'), ['invalid_value']],
    'relative on decimal' => [clause('subject.amount', 'gt', ['relative' => 'P1D']), ['invalid_value']],
    'missing value' => [clause('subject.amount', 'gt'), ['invalid_value']],
    'between needs two' => [clause('subject.amount', 'between', ['1']), ['invalid_value']],
    'in needs a list' => [clause('subject.priority', 'in', 'high'), ['invalid_value']],
    'unknown input' => [clause('input.missing', 'equals', true), ['unknown_input']],
    'declared input' => [clause('input.urgent', 'equals', true), []],
    'ancestor output' => [clause('steps.task.output.task_id', 'equals', 1), []],
    'non-ancestor output' => [clause('steps.done.output.x', 'equals', 1), ['invalid_reference']],
    'unknown root' => [clause('model.secret', 'equals', 1), ['unknown_field']],
    'bad ref' => [clause('subject.priority', 'equals', ['ref' => 'subject.nope']), ['unknown_field']],
    'bad shape' => [['field' => 'subject.amount'], ['invalid_config']],
    'empty group' => [['all' => []], ['invalid_config']],
    'mixed group' => [['all' => [clause('subject.amount', 'gt', '1')], 'any' => []], ['invalid_config']],
    'not with extra' => [['not' => clause('subject.amount', 'gt', '1'), 'x' => 1], ['invalid_config']],
    'party value' => [clause('subject.requester', 'equals', ['kind' => 'triggered_by']), []],
]);

// C-15: depth and clause caps.
it('enforces the condition depth and clause limits', function (): void {
    config()->set('workflows.conditions.max_depth', 2);
    config()->set('workflows.conditions.max_clauses', 2);

    expect(conditionErrors(['all' => [['any' => [clause('subject.amount', 'gt', '1')]]]]))->toContain('condition_too_complex')
        ->and(conditionErrors(['all' => [clause('subject.amount', 'gt', '1'), clause('subject.amount', 'gt', '1'), clause('subject.amount', 'gt', '1')]]))->toContain('condition_too_complex')
        ->and(conditionErrors(['all' => [clause('subject.amount', 'gt', '1')]]))->toBe([]);
});

it('handles the edge shapes of each operator', function (array $condition, array $input, bool $expected): void {
    $requester = user('Rita');
    $document = document(null, ['requester_id' => $requester->id, 'due_on' => '2026-11-12 08:00:00']);

    expect(holds($condition, $document, ['urgent' => true, 'note' => 'hello', ...$input], starter: $requester))->toBe($expected);
})->with([
    'list contains' => [clause('input.tags', 'contains', 'b'), ['tags' => ['a', 'b']], true],
    'list lacks' => [clause('input.tags', 'contains', 'z'), ['tags' => ['a', 'b']], false],
    'contains empty needle' => [clause('input.note', 'contains', ''), [], false],
    'between malformed' => [clause('input.count', 'between', ['1']), ['count' => 5], false],
    'in malformed' => [clause('input.count', 'in', 'x'), ['count' => 5], false],
    'party not a model' => [clause('input.note', 'equals', ['kind' => 'triggered_by']), [], false],
    'date bad literal' => [clause('subject.due_on', 'after', 'someday'), [], false],
    'date iso literal' => [clause('subject.due_on', 'before', '2026-11-13T09:00:00+00:00'), [], true],
    'bool vs string' => [clause('input.flag', 'equals', 'yes'), ['flag' => true], false],
    'scalar vs list' => [clause('input.note', 'equals', ['a']), [], false],
    'string ordering' => [clause('input.code', 'gt', 'a'), ['code' => 'b'], true],
    'all with junk' => [['all' => 'nope'], [], true],
    'any with junk' => [['any' => ['nope']], [], false],
    'not of junk' => [['not' => 'nope'], [], false],
]);

it('compares a party with a stored key', function (): void {
    $requester = user('Rita');
    $document = document(null, ['requester_id' => $requester->id]);

    expect(holds(clause('subject.requester', 'equals', (string) $requester->id), $document))->toBeTrue()
        ->and(holds(clause('subject.requester', 'equals', $requester->id + 1000), $document))->toBeFalse();
});
