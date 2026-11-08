<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Events\InstanceStarted;
use RoundlyConsulting\Workflows\Exceptions\DefinitionNotActiveException;
use RoundlyConsulting\Workflows\Exceptions\MissingTriggeringUserException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;

// Chat review C-1: one definition that refuses to start does not abort the emit's fan-out.

final class RegressionDocumentFiled
{
    public function __construct(public Document $document) {}
}

beforeEach(function (): void {
    $this->acme = company();
    $this->document = document($this->acme);

    $onEvent = static fn (array $settings = []): array => graph([
        node('start', 'trigger', ['trigger' => 'event', 'event' => 'document.submitted']),
        node('done', 'end'),
    ], [['start', 'done']], $settings);

    // `a` (the lower id) needs a triggering user the emit does not carry.
    published('a', $onEvent(['actor' => 'on_behalf_of']), $this->acme);
    published('b', $onEvent(), $this->acme);
    published('waiter', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'document.submitted']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), $this->acme);

    $this->waiter = Workflows::start('waiter', $this->document, $this->acme)->dispatch()->instance ?? throw new LogicException;
});

it('starts the other definitions and resumes the waits when one definition refuses to start', function (): void {
    expect($this->waiter->status)->toBe(InstanceStatus::Waiting);

    $result = Workflows::emit('document.submitted', $this->document);

    expect(array_map(static fn ($start): ?string => $start->definition, $result->started))->toBe(['b'])
        ->and($result->started[0]->created)->toBeTrue()
        ->and($result->failed)->toHaveCount(1)
        ->and($result->failed[0]->definition)->toBe('a')
        ->and($result->failed[0]->exception)->toBeInstanceOf(MissingTriggeringUserException::class)
        ->and($result->failed[0]->code())->toBe('missing_triggering_user')
        ->and($result->resumed)->toBe(1)
        ->and($this->waiter->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(WorkflowInstance::query()->whereHas('definition', static fn ($query) => $query->where('key', 'b'))->count())->toBe(1);
});

it('reaches the later definitions again when the same key is sent again', function (): void {
    Workflows::emit('document.submitted', $this->document, idempotencyKey: 'filed:1');
    $again = Workflows::emit('document.submitted', $this->document, idempotencyKey: 'filed:1');

    expect($again->started[0]->definition)->toBe('b')
        ->and($again->started[0]->created)->toBeFalse()
        ->and($again->failed[0]->definition)->toBe('a');
});

it('does not throw into the host request through a mapped event', function (): void {
    $this->refreshApplicationWithEvents([
        RegressionDocumentFiled::class => ['key' => 'document.submitted', 'subject' => 'document'],
    ]);

    event(new RegressionDocumentFiled($this->document));

    expect($this->waiter->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(WorkflowInstance::query()->whereHas('definition', static fn ($query) => $query->where('key', 'b'))->count())->toBe(1);
});

it('reports the refused definition to the exception handler through a mapped event', function (): void {
    $reported = [];
    app(ExceptionHandler::class)->reportable(static function (MissingTriggeringUserException $exception) use (&$reported): bool {
        $reported[] = $exception->context();

        return false;
    });
    $this->refreshApplicationWithEvents([
        RegressionDocumentFiled::class => ['key' => 'document.submitted', 'subject' => 'document'],
    ]);

    event(new RegressionDocumentFiled($this->document));

    expect($reported)->toBe([['code' => 'missing_triggering_user', 'key' => 'a']]);
});

it('reports a definition retired between the query and its lock and still signals the waits', function (): void {
    published('c', graph([
        node('start', 'trigger', ['trigger' => 'event', 'event' => 'document.submitted']),
        node('done', 'end'),
    ], [['start', 'done']]), $this->acme);

    // `b` starts first and retires `c` before its turn comes.
    Event::listen(InstanceStarted::class, function (InstanceStarted $event): void {
        if ($event->instance->definition?->key === 'b') {
            Workflows::definition('c', $this->acme)->retire();
        }
    });

    $result = Workflows::emit('document.submitted', $this->document, user: user());

    expect(array_map(static fn ($start): ?string => $start->definition, $result->started))->toBe(['a', 'b'])
        ->and($result->failed)->toHaveCount(1)
        ->and($result->failed[0]->definition)->toBe('c')
        ->and($result->failed[0]->exception)->toBeInstanceOf(DefinitionNotActiveException::class)
        ->and($result->resumed)->toBe(1);
});
