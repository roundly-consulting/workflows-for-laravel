<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\WorkflowsManager;

function fakeFlow(): array
{
    return graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]);
}

it('resolves one manager through the container, and the fake behind it after fake()', function (): void {
    $manager = app(WorkflowsManager::class);

    expect(app(WorkflowsManager::class))->toBe($manager)
        ->and(Workflows::getFacadeRoot())->toBe($manager);

    $fake = Workflows::fake();

    expect(app(WorkflowsManager::class))->toBe($fake)
        ->and(Workflows::getFacadeRoot())->toBe($fake);
});

// C-130: every assert passes on its call and fails without it.
it('records starts and asserts them', function (): void {
    published('flow', fakeFlow(), null, null);
    $fake = Workflows::fake();

    expect(fn () => $fake->assertStarted('flow'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingStarted())->not->toThrow(AssertionFailedError::class);

    $result = Workflows::start('flow')->input(['a' => 1])->dispatch();

    expect($result->instance)->toBeNull()
        ->and($result->created)->toBeFalse()
        ->and(WorkflowInstance::query()->count())->toBe(0);

    $fake->assertStarted('flow');
    $fake->assertStarted('flow', static fn ($data): bool => $data->input === ['a' => 1], times: 1);
    $fake->assertNotStarted('other');

    expect(fn () => $fake->assertStarted('flow', static fn ($data): bool => $data->input === []))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertStarted('flow', times: 2))->toThrow(AssertionFailedError::class, 'started 1 time(s)')
        ->and(fn () => $fake->assertNotStarted('flow'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingStarted())->toThrow(AssertionFailedError::class);
});

it('records emits and asserts them', function (): void {
    $fake = Workflows::fake();

    expect(fn () => $fake->assertEmitted('doc.signed'))->toThrow(AssertionFailedError::class);

    $result = Workflows::emit('doc.signed', document(), ['by' => 'x']);

    expect($result->started)->toBe([]);

    $fake->assertEmitted('doc.signed');
    $fake->assertEmitted('doc.signed', static fn ($data): bool => $data->payload === ['by' => 'x']);
    $fake->assertNotEmitted('doc.other');

    expect(fn () => $fake->assertNotEmitted('doc.signed'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertEmitted('doc.signed', static fn (): bool => false))->toThrow(AssertionFailedError::class);
});

it('records publishes, retries, cancels, wakes and touches', function (): void {
    Workflows::definitions()->create('flow', 'Flow');
    Workflows::definition('flow')->edit(fakeFlow());
    $instance = WorkflowInstance::factory()->create();
    $other = WorkflowInstance::factory()->create();
    $fake = Workflows::fake();

    expect(fn () => $fake->assertPublished('flow'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertRetried($instance))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertCancelled($instance))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertWoken())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertTouched())->toThrow(AssertionFailedError::class);

    expect(Workflows::definition('flow')->publish())->toBeNull();
    Workflows::instances()->retry($instance);
    Workflows::instances()->cancel($instance, 'no');
    expect(Workflows::wake()->timers)->toBe(0)
        ->and(Workflows::prune()->instances)->toBe(0)
        ->and(Workflows::signal('x'))->toBe(0);

    // The trait goes through the manager, so the fake sees it.
    document()->workflows()->touch();

    $fake->assertPublished('flow');
    $fake->assertRetried($instance);
    $fake->assertCancelled($instance);
    $fake->assertWoken();
    $fake->assertTouched();

    expect(fn () => $fake->assertRetried($other))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertCancelled($other))->toThrow(AssertionFailedError::class)
        ->and(Workflows::definition('flow')->model()->current_version_id)->toBeNull()
        ->and($fake->recorded(WorkflowOperation::Prune))->toHaveCount(1)
        ->and($fake->recorded(WorkflowOperation::Signal)[0]->context['key'])->toBe('x')
        ->and($fake->recorded())->toHaveCount(7);
});

it('sees the cancel a deleted subject fires through the trait', function (): void {
    config()->set('workflows.subjects_on_deleted', 'cancel');
    $document = document();
    $instance = WorkflowInstance::factory()->create(['subject_type' => $document->getMorphClass(), 'subject_id' => $document->id]);
    $fake = Workflows::fake();

    $document->delete();

    $fake->assertCancelled($instance);
    expect($instance->refresh()->status->value)->toBe('running');
});

it('records and runs in passthrough, and still runs dry runs', function (): void {
    published('flow', fakeFlow(), null, null);
    $fake = Workflows::fake()->passthrough();

    $instance = Workflows::start('flow')->dispatch()->instance;

    expect($instance)->toBeInstanceOf(WorkflowInstance::class)
        ->and(Workflows::definition('flow')->dryRun()->run()->nodes())->toBe(['start', 'done'])
        ->and($fake->recorded(WorkflowOperation::DryRun))->toBe([]);

    $fake->assertStarted('flow', times: 1);
    $fake->passthrough(false);
    Workflows::start('flow')->dispatch();

    expect(WorkflowInstance::query()->count())->toBe(1);
});
