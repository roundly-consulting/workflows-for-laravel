<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Events\DefinitionRetired;
use RoundlyConsulting\Workflows\Events\DraftSaved;
use RoundlyConsulting\Workflows\Events\VersionPublished;
use RoundlyConsulting\Workflows\Exceptions\DefinitionKeyTakenException;
use RoundlyConsulting\Workflows\Exceptions\DefinitionNotActiveException;
use RoundlyConsulting\Workflows\Exceptions\DraftConflictException;
use RoundlyConsulting\Workflows\Exceptions\InvalidDefinitionException;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Exceptions\NothingToPublishException;
use RoundlyConsulting\Workflows\Exceptions\PublishedVersionImmutableException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Exceptions\UnknownSubjectTypeException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Drafts;

function simple(string $title = 'x'): array
{
    return graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => $title]), node('done', 'end')], [['start', 'task'], ['task', 'done']]);
}

it('creates a draft definition with a minimal version 1', function (): void {
    $definition = Workflows::definitions()->create(key: 'flow', name: 'Flow', subjectType: 'document', owner: company(), description: 'D');
    $draft = Workflows::definition('flow', $definition?->owner)->draft();

    expect($definition?->status)->toBe(DefinitionStatus::Draft)
        ->and($definition?->owner_scope)->toStartWith(company()->getMorphClass().':')
        ->and($draft?->number)->toBe(1)
        ->and($draft?->status)->toBe(VersionStatus::Draft)
        ->and(Workflows::definition('flow', $definition?->owner)->validate()->valid())->toBeTrue();
});

// C-33
it('keeps definition keys unique per owner and valid', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');

    Workflows::definitions()->create(key: 'flow', name: 'A', owner: $acme);
    Workflows::definitions()->create(key: 'flow', name: 'B', owner: $globex);
    Workflows::definitions()->create(key: 'flow', name: 'App-wide');

    expect(Workflows::definitions()->all($acme))->toHaveCount(1)
        ->and(Workflows::definitions()->exists('flow'))->toBeTrue()
        ->and(fn () => Workflows::definitions()->create(key: 'flow', name: 'Again', owner: $acme))->toThrow(DefinitionKeyTakenException::class)
        ->and(fn () => Workflows::definitions()->create(key: 'Not Valid', name: 'X'))->toThrow(InvalidDefinitionException::class)
        ->and(fn () => Workflows::definitions()->create(key: 'x', name: 'X', subjectType: 'nope'))->toThrow(UnknownSubjectTypeException::class);
});

// C-139
it('refuses a trashed key with a clear error and restores it', function (): void {
    $definition = Workflows::definitions()->create(key: 'flow', name: 'Flow');
    $definition?->delete();

    try {
        Workflows::definitions()->create(key: 'flow', name: 'Again');
        $this->fail('Expected DefinitionKeyTakenException.');
    } catch (DefinitionKeyTakenException $exception) {
        expect($exception->errorCode())->toBe('definition_key_trashed')->and($exception->status())->toBe(409);
    }

    expect(Workflows::definitions()->find('flow'))->toBeNull()
        ->and(Workflows::definitions()->restore('flow')?->trashed())->toBeFalse()
        ->and(Workflows::definitions()->find('flow'))->not->toBeNull();
});

// C-32
it('saves half-finished drafts and validates fully only on publish', function (): void {
    Event::fake([DraftSaved::class]);
    Workflows::definitions()->create(key: 'flow', name: 'Flow', subjectType: 'document');
    $half = graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', [])], [['start', 'task']]);

    $draft = Workflows::definition('flow')->edit($half);

    expect($draft?->graph)->toBe($half)->and(Workflows::definition('flow')->validate()->valid())->toBeFalse();
    Event::assertDispatched(DraftSaved::class);

    try {
        Workflows::definition('flow')->publish();
        $this->fail('Expected InvalidGraphException.');
    } catch (InvalidGraphException $exception) {
        expect($exception->validationReport()->valid())->toBeFalse()->and($exception->status())->toBe(422);
    }

    expect(fn () => Workflows::definition('flow')->edit(['schema' => 'nope']))->toThrow(InvalidGraphException::class);
});

it('publishes the draft and supersedes the previous version', function (): void {
    Event::fake([VersionPublished::class]);
    $admin = user('Admin');
    Workflows::definitions()->create(key: 'flow', name: 'Flow', subjectType: 'document');
    Workflows::definition('flow')->edit(simple('one'));
    $v1 = Workflows::definition('flow')->publish($admin);

    Workflows::definition('flow')->edit(simple('two'));
    $v2 = Workflows::definition('flow')->publish($admin);
    $definition = Workflows::definition('flow')->model();

    expect($v1?->number)->toBe(1)->and($v2?->number)->toBe(2)
        ->and($v1?->refresh()->status)->toBe(VersionStatus::Superseded)
        ->and($v2?->status)->toBe(VersionStatus::Published)
        ->and($v2?->publisher_id)->toBe($admin->id)
        ->and($definition->status)->toBe(DefinitionStatus::Active)
        ->and($definition->current_version_id)->toBe($v2?->id)
        ->and(Workflows::definition('flow')->versions()->pluck('number')->all())->toBe([1, 2])
        ->and(Workflows::definition('flow')->version(1)?->id)->toBe($v1?->id)
        ->and(Workflows::definition('flow')->version(9))->toBeNull();

    Event::assertDispatched(VersionPublished::class, 2);
});

// C-37
it('throws when there is no draft to publish', function (): void {
    published('flow', simple());

    Workflows::definition('flow')->publish();
})->throws(NothingToPublishException::class);

// C-34
it('keeps a published version immutable', function (): void {
    published('flow', simple());
    $version = Workflows::definition('flow')->model()->currentVersion;

    expect($version)->toBeInstanceOf(WorkflowVersion::class);

    $version->schedule_cursor_at = now();
    $version->save();

    $version->graph = simple('changed');
    $version->save();
})->throws(PublishedVersionImmutableException::class, 'graph');

// C-35
it('refuses a stale draft edit', function (): void {
    Workflows::definitions()->create(key: 'flow', name: 'Flow', subjectType: 'document');
    $checksum = Workflows::definition('flow')->draft()?->checksum;

    Workflows::definition('flow')->edit(simple('first'), $checksum);
    Workflows::definition('flow')->edit(simple('second'), $checksum);
})->throws(DraftConflictException::class);

// C-36
it('keeps at most one draft per definition', function (): void {
    published('flow', simple());

    Workflows::definition('flow')->edit(simple('a'));
    Workflows::definition('flow')->edit(simple('b'));

    expect(WorkflowVersion::query()->where('status', 'draft')->count())->toBe(1)
        ->and(Workflows::definition('flow')->draft()?->number)->toBe(2);
});

it('discards a draft, and resets a never-published one', function (): void {
    published('flow', simple());
    Workflows::definition('flow')->edit(simple('b'));
    Workflows::definition('flow')->discardDraft();

    Workflows::definitions()->create(key: 'fresh', name: 'Fresh', subjectType: 'document');
    Workflows::definition('fresh')->edit(simple('b'));
    Workflows::definition('fresh')->discardDraft();

    expect(Workflows::definition('flow')->draft())->toBeNull()
        ->and(Workflows::definition('fresh')->draft()?->graph['nodes'][1]['type'])->toBe('end')
        ->and(fn () => Workflows::definition('flow')->discardDraft())->toThrow(NothingToPublishException::class);
});

// C-38
it('refuses new starts on a retired definition and re-activates it', function (): void {
    Event::fake([DefinitionRetired::class]);
    $company = company();
    published('flow', simple(), $company);

    Workflows::definition('flow', $company)->retire();
    Event::assertDispatched(DefinitionRetired::class);

    expect(fn () => Workflows::start('flow', document($company), $company)->dispatch())->toThrow(DefinitionNotActiveException::class);

    Workflows::definition('flow', $company)->activate();

    expect(Workflows::start('flow', document($company), $company)->dispatch()->created)->toBeTrue();
});

it('cannot activate a definition that was never published', function (): void {
    Workflows::definitions()->create(key: 'flow', name: 'Flow');

    Workflows::definition('flow')->activate();
})->throws(NothingToPublishException::class);

// C-110
it('never finds a definition of another owner', function (): void {
    $acme = company('Acme');
    published('flow', simple(), $acme);

    expect(fn () => Workflows::definition('flow', company('Globex'))->model())->toThrow(UnknownDefinitionException::class)
        ->and(fn () => Workflows::definition('flow')->model())->toThrow(UnknownDefinitionException::class)
        ->and(Workflows::definitions()->find('flow', $acme))->not->toBeNull();
});

it('copies the current version into a new draft', function (): void {
    published('flow', simple('one'));

    $draft = Drafts::findOrCreate(Workflows::definition('flow')->model());

    expect($draft->number)->toBe(2)->and($draft->graph)->toBe(simple('one'));
});

it('rotates the webhook address', function (): void {
    published('hook', graph([node('start', 'trigger', ['trigger' => 'webhook', 'idempotency' => ['from' => 'header:Idempotency-Key']]), node('done', 'end')], [['start', 'done']]), null, null);
    $before = Workflows::definition('hook')->model()->webhook_id;

    $after = Workflows::definition('hook')->rotateWebhook()?->webhook_id;

    expect($before)->toBeString()->and($after)->toBeString()->and($after)->not->toBe($before);
});
