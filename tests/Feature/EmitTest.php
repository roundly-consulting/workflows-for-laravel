<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Exceptions\InvalidEventKeyException;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Doctor;
use RoundlyConsulting\Workflows\Support\EventMappings;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Events\DocumentPriced;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Events\DocumentSigned;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

final class DocumentSubmitted
{
    public function __construct(public Document $document, public ?User $user = null) {}
}

final class DocumentArchived
{
    public function __construct(public Document $document) {}
}

final class SelfMapped implements WorkflowEvent
{
    public function __construct(private Document $document) {}

    public function workflowKey(): string
    {
        return 'document.self';
    }

    public function workflowSubject(): ?Model
    {
        return $this->document;
    }

    public function workflowPayload(): array
    {
        return ['via' => 'contract'];
    }

    public function workflowUser(): ?Model
    {
        return null;
    }

    public function workflowIdempotencyKey(): ?string
    {
        return 'self:'.$this->document->id;
    }
}

function onEvent(string $key, array $trigger = []): array
{
    return graph([node('start', 'trigger', ['trigger' => 'event', 'event' => $key, ...$trigger]), node('done', 'end')], [['start', 'done']]);
}

// C-23 + A-8 (emit variant)
it('starts one instance per matching definition and dedupes a repeated key', function (): void {
    $acme = company();
    $document = document($acme);
    published('a', onEvent('document.submitted'), $acme);
    published('b', onEvent('document.submitted'), $acme);
    published('global', onEvent('document.submitted'), null);
    published('other', onEvent('document.other'), $acme);

    $first = Workflows::emit('document.submitted', $document, ['amount' => 5], 'submit:1');
    $again = Workflows::emit('document.submitted', $document, ['amount' => 5], 'submit:1');

    expect($first->started)->toHaveCount(3)
        ->and(array_map(static fn ($result): ?string => $result->definition, $first->started))->toBe(['a', 'b', 'global'])
        ->and(collect($again->started)->every(fn ($result): bool => $result->created === false))->toBeTrue()
        ->and(WorkflowInstance::query()->count())->toBe(3)
        ->and(WorkflowInstance::query()->first()?->trigger_payload['trigger'])->toEqual(['event' => 'document.submitted', 'payload' => ['amount' => 5]]);
});

// C-86
it('never starts another owner\'s definition', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    published('flow', onEvent('document.submitted'), $globex);

    expect(Workflows::emit('document.submitted', document($acme))->started)->toBe([])
        ->and(fn () => Workflows::emit('document.submitted', document($acme), owner: $globex))->toThrow(SubjectOwnerMismatchException::class);
});

// C-24 for events
it('skips a definition whose trigger condition is false', function (): void {
    published('flow', onEvent('document.submitted', ['condition' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '100']]));

    $result = Workflows::emit('document.submitted', document(null, ['amount' => '5']));

    expect($result->skipped())->toHaveCount(1)->and($result->skipped()[0]->skipped)->toBe('condition_false');
});

// C-25
it('refuses internal and malformed keys', function (string $key): void {
    Workflows::emit($key);
})->with(['@approvals.request.1', 'Bad Key', ''])->throws(InvalidEventKeyException::class);

// C-22 + C-136
it('emits mapped Laravel events after the host transaction commits', function (): void {
    $this->refreshApplicationWithEvents([
        DocumentSubmitted::class => ['key' => 'document.submitted', 'subject' => 'document', 'user' => 'user'],
        SelfMapped::class => '*',
    ]);
    published('flow', onEvent('document.submitted'));
    published('self', onEvent('document.self'));

    $document = document();

    DB::transaction(function () use ($document): void {
        event(new DocumentSubmitted($document));

        expect(WorkflowInstance::query()->count())->toBe(0);
    });

    expect(WorkflowInstance::query()->count())->toBe(1);

    try {
        DB::transaction(function () use ($document): void {
            event(new DocumentSubmitted($document));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    event(new DocumentArchived($document));
    event(new SelfMapped($document));
    event(new SelfMapped($document));

    expect(WorkflowInstance::query()->count())->toBe(2)
        ->and(Workflows::triggers()->events())->toBe(['DocumentSubmitted' => 'document.submitted', 'SelfMapped' => '*']);
});

it('refuses a malformed event map', function (mixed $events): void {
    config()->set('workflows.events', $events);

    WorkflowsConfig::events();
})->with([
    'not a map' => ['x'],
    'no key' => [[DocumentSubmitted::class => ['subject' => 'document']]],
    'numeric class' => [['document.submitted']],
])->throws(InvalidWorkflowsConfigurationException::class);

// C-137 + C-53
it('signals only waits of the same owner, and only after they started', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    $graph = graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'stock.arrived', 'match' => 'any']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]);

    published('flow', $graph, $acme);
    published('flow', $graph, $globex);

    Workflows::emit('stock.arrived', document($acme));
    $mine = Workflows::start('flow', document($acme), $acme)->dispatch()->instance;
    $theirs = Workflows::start('flow', document($globex), $globex)->dispatch()->instance;

    expect($mine)->toBeWaitingAt('wait');

    $result = Workflows::emit('stock.arrived', document($acme));

    expect($result->resumed)->toBe(1)
        ->and($mine?->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($theirs?->refresh()->status)->toBe(InstanceStatus::Waiting)
        ->and(Workflows::emit('stock.arrived')->resumed)->toBe(0);
});

it('matches a waiting step to its own subject by default', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'doc.signed']), node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]));

    $mine = document();
    $instance = Workflows::start('flow', $mine)->dispatch()->instance;

    expect(Workflows::emit('doc.signed', document())->resumed)->toBe(0)
        ->and(Workflows::emit('doc.signed', $mine, ['by' => 'x'])->resumed)->toBe(1)
        ->and($instance?->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($instance?->stepRuns()->where('node_id', 'wait')->latest('id')->first()?->output['payload'])->toBe(['by' => 'x']);
});

// W-10
it('maps an event in code with subject, user, payload and key', function (): void {
    Workflows::triggers()->map(DocumentPriced::class, 'document.priced', subject: 'document', user: 'by', payload: ['amount', 'currency', 'by'], idempotencyKey: 'submissionId');
    published('flow', onEvent('document.priced', ['condition' => ['field' => 'trigger.payload.amount', 'op' => 'equals', 'value' => '120']]));
    $ada = user('Ada');

    event(new DocumentPriced(document(), $ada, '120', 'EUR', 'sub-1'));
    $instance = WorkflowInstance::query()->sole();

    expect($instance->trigger_payload['trigger'])->toEqual(['event' => 'document.priced', 'payload' => ['amount' => '120', 'currency' => 'EUR', 'by' => ['id' => $ada->id, 'type' => 'User']]])
        ->and($instance->starter_id)->toBe($ada->id)
        ->and(Workflows::triggers()->events())->toBe(['DocumentPriced' => 'document.priced']);
});

it('starts one instance for a repeated mapped event with an idempotency key', function (): void {
    Workflows::triggers()->map(DocumentPriced::class, 'document.priced', subject: 'document', idempotencyKey: 'submissionId');
    published('flow', onEvent('document.priced'));
    $document = document();

    event(new DocumentPriced($document, submissionId: 'sub-1'));
    event(new DocumentPriced($document, submissionId: 'sub-1'));
    event(new DocumentPriced($document, submissionId: 'sub-2'));

    expect(WorkflowInstance::query()->count())->toBe(2);
});

it('listens to a self-describing WorkflowEvent', function (): void {
    Workflows::triggers()->listen(DocumentSigned::class);
    Workflows::triggers()->listen(DocumentSigned::class);
    published('flow', onEvent('document.signed'));
    $document = document();

    event(new DocumentSigned($document));
    event(new DocumentSigned($document));

    expect(WorkflowInstance::query()->count())->toBe(1)
        ->and(WorkflowInstance::query()->sole()->trigger_payload['trigger']['payload'])->toBe(['signed' => true])
        ->and(Workflows::triggers()->events())->toBe(['DocumentSigned' => '*']);
});

it('refuses listen() for an event that is not a WorkflowEvent', function (): void {
    expect(fn () => Workflows::triggers()->listen(DocumentPriced::class))->toThrow(InvalidWorkflowsConfigurationException::class, 'WorkflowEvent')
        ->and(fn () => Workflows::triggers()->map(DocumentPriced::class, '*'))->toThrow(InvalidWorkflowsConfigurationException::class, 'listen()')
        ->and(fn () => Workflows::triggers()->map(DocumentPriced::class, 'x', payload: ['ok', '']))->toThrow(InvalidWorkflowsConfigurationException::class, 'payload');
});

it('reads payload from a method', function (): void {
    Workflows::triggers()->map(DocumentPriced::class, 'document.priced', subject: 'document', payload: 'workflowData');
    published('flow', onEvent('document.priced'));
    $ada = user('Ada');

    event(new DocumentPriced(document(), $ada, '99'));

    expect(WorkflowInstance::query()->sole()->trigger_payload['trigger']['payload'])->toEqual(['amount' => '99', 'by' => ['id' => $ada->id, 'type' => 'User']]);
});

it('emits a mapped event only after the host transaction commits', function (): void {
    Workflows::triggers()->map(DocumentPriced::class, 'document.priced', subject: 'document');
    published('flow', onEvent('document.priced'));
    $document = document();

    DB::transaction(function () use ($document): void {
        event(new DocumentPriced($document));

        expect(WorkflowInstance::query()->count())->toBe(0);
    });

    try {
        DB::transaction(function () use ($document): void {
            event(new DocumentPriced($document));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(WorkflowInstance::query()->count())->toBe(1);
});

it('lets the last mapping win and reports the duplicate', function (): void {
    config()->set('workflows.events', [DocumentPriced::class => ['key' => 'document.old', 'subject' => 'document', 'idempotency_key' => 'submissionId', 'payload' => ['amount']]]);

    expect(app(EventMappings::class)->find(DocumentPriced::class)?->payload)->toBe(['amount']);

    Workflows::triggers()->map(DocumentPriced::class, 'document.priced', subject: 'document');
    published('flow', onEvent('document.priced'));

    event(new DocumentPriced(document()));

    expect(WorkflowInstance::query()->count())->toBe(1)
        ->and(app(EventMappings::class)->duplicates())->toBe([DocumentPriced::class])
        ->and(collect(app(Doctor::class)->run())->filter(fn ($finding): bool => str_contains($finding->message, 'mapped more than once')))->toHaveCount(1);
});

it('refuses a malformed payload in the config map', function (): void {
    config()->set('workflows.events', [DocumentPriced::class => ['key' => 'x', 'payload' => [1]]]);

    WorkflowsConfig::events();
})->throws(InvalidWorkflowsConfigurationException::class, 'payload');
