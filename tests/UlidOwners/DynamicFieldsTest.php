<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\ConditionWaits;
use RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures\Ticket;

it('gives each owner its own dynamic fields in validation and the pickers', function (): void {
    $acme = tenant('Acme', [['name' => 'score', 'type' => 'integer', 'label' => 'Score']]);
    $globex = tenant('Globex', [['name' => 'region_code', 'type' => 'string', 'label' => 'Region code']]);
    $graph = branching(['field' => 'subject.score', 'op' => 'gt', 'value' => 5]);

    $forAcme = Workflows::versions()->validate($graph, 'ticket', $acme);
    $forGlobex = Workflows::versions()->validate($graph, 'ticket', $globex);

    expect($forAcme->valid())->toBeTrue()
        ->and($forGlobex->has(ValidationCode::UnknownField, 'check'))->toBeTrue()
        ->and(Workflows::subjects()->export('ticket', $acme)->field('score'))->toBeInstanceOf(FieldDefinition::class)
        ->and(Workflows::subjects()->export('ticket', $acme)->field('score')?->dynamic)->toBeTrue()
        ->and(Workflows::subjects()->export('ticket', $acme)->field('region_code'))->toBeNull()
        ->and(Workflows::subjects()->export('ticket', $globex)->field('region_code')?->type)->toBe(FieldType::String)
        ->and(Workflows::subjects()->export('ticket', $globex)->field('score'))->toBeNull()
        // Without an owner (app-wide definitions) the resolver gets null: registered fields only.
        ->and(array_keys(Workflows::subjects()->export('ticket')->fields))->toBe(['title', 'requester'])
        ->and(array_column(Workflows::subjects()->export('ticket', $acme)->toArray()['fields'], 'name'))->toBe(['title', 'requester', 'score']);
});

it('reads dynamic fields through the model resolver, and the owner by path, at run time', function (): void {
    $acme = tenant('Acme', [['name' => 'score', 'type' => 'integer', 'label' => 'Score']], ['region' => 'eu']);
    published('triage', branching(['all' => [
        ['field' => 'subject.score', 'op' => 'gt', 'value' => 5],
        ['field' => 'owner.region', 'op' => 'equals', 'value' => 'eu'],
    ]]), $acme, 'ticket');

    $urgent = Workflows::start('triage', ticket($acme, ['custom' => ['score' => '9']]), $acme)->dispatch()->instance;
    $minor = Workflows::start('triage', ticket($acme, ['custom' => ['score' => 2]]), $acme)->dispatch()->instance;

    expect($urgent)->toHaveCompletedVia(['start', 'check', 'high'])
        ->and($minor)->toHaveCompletedVia(['start', 'check', 'low'])
        ->and(is_string($urgent?->owner_id) && strlen($urgent->owner_id) === 26)->toBeTrue()
        ->and($urgent?->subject_id)->toBe(Ticket::query()->orderBy('id')->firstOrFail()->id);
});

it('refuses to publish another owner\'s field, an owner-only field app-wide, and a write to a dynamic field', function (): void {
    $acme = tenant('Acme', [['name' => 'score', 'type' => 'integer', 'label' => 'Score']], ['region' => 'eu']);
    $globex = tenant('Globex');

    Workflows::definitions()->create('triage', 'Triage', 'ticket', $globex);
    Workflows::definition('triage', $globex)->edit(branching(['field' => 'owner.region', 'op' => 'equals', 'value' => 'eu']));

    $write = graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('set', 'set_field', ['field' => 'subject.score', 'value' => 3]),
        node('done', 'end'),
    ], [['start', 'set'], ['set', 'done']]);

    expect(Workflows::definition('triage', $globex)->validate()->has(ValidationCode::UnknownField, 'check'))->toBeTrue()
        ->and(Workflows::versions()->validate(branching(['field' => 'subject.score', 'op' => 'gt', 'value' => 1]), 'ticket')->has(ValidationCode::UnknownField, 'check'))->toBeTrue()
        ->and(Workflows::versions()->validate($write, 'ticket', $acme)->has(ValidationCode::FieldNotWritable, 'set'))->toBeTrue()
        ->and(Workflows::subjects()->export('ticket', $acme)->field('score')?->writable)->toBeFalse();
});

it('keeps a registered field when a dynamic field takes its name', function (): void {
    $acme = tenant('Acme', [['name' => 'title', 'type' => 'integer', 'label' => 'Shadow']]);
    $title = Workflows::subjects()->export('ticket', $acme)->field('title');

    expect($title?->type)->toBe(FieldType::String)
        ->and($title?->writable)->toBeTrue()
        ->and($title?->dynamic)->toBeFalse()
        // Resolved once, a definition carries no resolver: resolving it again changes nothing.
        ->and(Workflows::subjects()->export('ticket', $acme)->forOwner(tenant('Other'))->field('title')?->label)->toBe('Title');
});

it('refuses a resolver that returns anything but valid fields', function (mixed $returned): void {
    Workflows::subjects()->register(Workflows::subject('broken', Ticket::class)->dynamicFields(static fn (?Model $owner): array => [$returned]));

    Workflows::versions()->validate(branching(['field' => 'subject.title', 'op' => 'equals', 'value' => 'x']), 'broken', tenant());
})->with([
    'not a field' => ['score'],
    'a bad name' => [new FieldDefinition('Bad Name', FieldType::String, 'Bad')],
])->throws(InvalidWorkflowsConfigurationException::class, 'dynamicFields() resolver of subject type [broken]');

it('treats every change of a subject with dynamic fields as a possible condition change', function (): void {
    $ticket = ticket(tenant());
    $ticket->update(['custom' => ['score' => 4]]);

    expect(ConditionWaits::affectedBy($ticket))->toBeTrue();
});
