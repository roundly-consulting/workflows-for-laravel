<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\PartyResolver;
use RoundlyConsulting\Workflows\Support\TriggerInputs;
use RoundlyConsulting\Workflows\Support\ValueScope;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

// Dual review O-10 / F-7: a `party` trigger input carries a person; integer enum options validate.

it('accepts a user id as a party input and reads it back as that user', function (): void {
    $ada = user('Ada');
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'approver', 'type' => 'party', 'required' => true]]]),
        node('check', 'condition', ['when' => ['field' => 'input.approver', 'op' => 'equals', 'value' => ['kind' => 'triggered_by']]]),
        node('done', 'end'),
    ], [['start', 'check'], ['check', 'done', 'true'], ['check', 'done', 'false']]), null, null);

    $instance = Workflows::start('flow')->by($ada)->input(['approver' => $ada->id])->dispatch()->instance ?? throw new LogicException;

    expect(StepRun::query()->where('instance_id', $instance->id)->where('node_id', 'check')->value('outcome'))->toBe('true')
        ->and($instance->trigger_payload['input'])->toBe(['approver' => $ada->id]);
});

it('refuses a party input that names no user', function (): void {
    $trigger = ['inputs' => [['name' => 'approver', 'type' => 'party']]];

    expect(fn () => TriggerInputs::validate($trigger, ['approver' => 999]))->toThrow(InvalidTriggerInputException::class)
        ->and(fn () => TriggerInputs::validate($trigger, ['approver' => ['kind' => 'user']]))->toThrow(InvalidTriggerInputException::class)
        ->and(TriggerInputs::validate($trigger, ['approver' => user('Bo')]))->toBe(['approver' => User::query()->where('name', 'Bo')->value('id')]);
});

it('resolves a field party read from a party input', function (): void {
    $rita = user('Rita');
    $scope = new ValueScope(null, null, null, null, ['p' => (string) $rita->id], [], static fn (string $node): ?array => null, Clock::now(), 'UTC', null, ['p' => FieldType::Party]);

    $resolved = app(PartyResolver::class)->resolve(PartyReference::listFrom([['kind' => 'field', 'field' => 'input.p']]), $scope);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->is($rita))->toBeTrue();
});

it('accepts integer enum options', function (): void {
    $trigger = ['inputs' => [['name' => 'level', 'type' => 'enum', 'options' => [1, 2]]]];

    expect(TriggerInputs::validate($trigger, ['level' => 2]))->toBe(['level' => 2])
        ->and(fn () => TriggerInputs::validate($trigger, ['level' => 3]))->toThrow(InvalidTriggerInputException::class);
});

it('leaves an optional enum input out', function (): void {
    expect(TriggerInputs::validate(['inputs' => [['name' => 'level', 'type' => 'enum', 'options' => [1, 2]], ['name' => 'note', 'type' => 'string']]], ['note' => 'x']))->toBe(['note' => 'x']);
});
