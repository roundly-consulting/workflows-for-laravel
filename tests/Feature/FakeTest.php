<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\DataTransferObjects\ImportResult;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\Trigger;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Testing\ScriptedStepType;
use RoundlyConsulting\Workflows\Testing\WorkflowsFake;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\FixtureServiceProvider;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Task;
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

    expect(Workflows::definition('flow')->publish()->exists)->toBeFalse();
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

// W-02 / W-05e
it('records rotateWebhook as its own operation', function (): void {
    Workflows::definitions()->create('flow', 'Flow');
    $fake = Workflows::fake();

    Workflows::definition('flow')->rotateWebhook();

    expect($fake->recorded(WorkflowOperation::Edit))->toBe([])
        ->and($fake->recorded(WorkflowOperation::from('rotate_webhook')))->toHaveCount(1);
});

// W-01
it('returns an unsaved stand-in for every faked mutation', function (string $method, Closure $call, string $type, ?string $key): void {
    Workflows::definitions()->create('flow', 'Flow');
    Workflows::definition('flow')->edit(fakeFlow());
    Workflows::fake();

    $result = $call();

    expect($result)->toBeInstanceOf($type);

    $model = $result instanceof ImportResult ? $result->definition : $result;

    expect($model->exists)->toBeFalse();

    if ($key !== null) {
        expect($model instanceof WorkflowDefinition ? $model->key : null)->toBe($key);
    }
})->with([
    'create' => ['create', fn () => Workflows::definitions()->create('onboarding', 'Onboarding'), WorkflowDefinition::class, 'onboarding'],
    'createWith' => ['createWith', fn () => Workflows::definitions()->createWith(new CreateDefinitionData('onboarding', 'Onboarding')), WorkflowDefinition::class, 'onboarding'],
    'restore' => ['restore', fn () => Workflows::definitions()->restore('flow'), WorkflowDefinition::class, 'flow'],
    'discardDraft' => ['discardDraft', fn () => Workflows::definition('flow')->discardDraft(), WorkflowDefinition::class, 'flow'],
    'retire' => ['retire', fn () => Workflows::definition('flow')->retire(), WorkflowDefinition::class, 'flow'],
    'activate' => ['activate', fn () => Workflows::definition('flow')->activate(), WorkflowDefinition::class, 'flow'],
    'rotateWebhook' => ['rotateWebhook', fn () => Workflows::definition('flow')->rotateWebhook(), WorkflowDefinition::class, 'flow'],
    'edit' => ['edit', fn () => Workflows::definition('flow')->edit(fakeFlow()), WorkflowVersion::class, null],
    'publish' => ['publish', fn () => Workflows::definition('flow')->publish(), WorkflowVersion::class, null],
    'resolveFlag' => ['resolveFlag', fn () => Workflows::definition('flow')->resolveFlag('start', '/config'), WorkflowVersion::class, null],
    'import' => ['import', fn () => Workflows::versions()->import(['schema' => 'roundly.workflows.template/v1', 'definition' => ['key' => 'tpl', 'name' => 'Tpl'], 'graph' => fakeFlow()]), ImportResult::class, 'tpl'],
]);

it('hands retry and cancel back the instance they were given under the fake', function (): void {
    $instance = WorkflowInstance::factory()->create();
    Workflows::fake();

    expect(Workflows::instances()->retry($instance))->toBe($instance)
        ->and(Workflows::instances()->cancel($instance))->toBe($instance)
        ->and(Workflows::triggers()->webhook('wh_1', [])->skipped)->toBe('faked')
        ->and(Workflows::for(document())->touch())->toBe(0);
});

it('runs the real action and returns its result under passthrough', function (): void {
    Workflows::fake()->passthrough();

    $definition = Workflows::definitions()->create('flow', 'Flow');

    expect($definition->exists)->toBeTrue()
        ->and(Workflows::definition('flow')->edit(fakeFlow())->exists)->toBeTrue()
        ->and(Workflows::definition('flow')->publish()->number)->toBe(1);
});

it('reports faked starts as skipped with reason faked', function (): void {
    published('flow', fakeFlow(), null, null);
    Workflows::fake();

    $result = Workflows::start('flow')->dispatch();

    expect($result->instance)->toBeNull()
        ->and($result->wasSkipped())->toBeTrue()
        ->and($result->skipped)->toBe('faked')
        ->and($result->definition)->toBe('flow');
});

// W-02
/**
 * One row per assertion: what to call so it passes, then the assertion itself.
 *
 * @return array<string, array{0: Closure(): mixed, 1: Closure(WorkflowsFake): mixed}>
 */
function fakeAssertions(): array
{
    return [
        'assertStarted' => [fn () => Workflows::start('flow')->dispatch(), fn (WorkflowsFake $f) => $f->assertStarted('flow')],
        'assertEmitted' => [fn () => Workflows::emit('doc.signed'), fn (WorkflowsFake $f) => $f->assertEmitted('doc.signed')],
        'assertCreated' => [fn () => Workflows::definitions()->create('onboarding', 'O'), fn (WorkflowsFake $f) => $f->assertCreated('onboarding')],
        'assertEdited' => [fn () => Workflows::definition('flow')->edit(fakeFlow()), fn (WorkflowsFake $f) => $f->assertEdited('flow', static fn (array $graph): bool => count($graph['nodes']) === 2)],
        'assertPublished' => [fn () => Workflows::definition('flow')->publish(), fn (WorkflowsFake $f) => $f->assertPublished('flow')],
        'assertRetired' => [fn () => Workflows::definition('flow')->retire(), fn (WorkflowsFake $f) => $f->assertRetired('flow')],
        'assertActivated' => [fn () => Workflows::definition('flow')->activate(), fn (WorkflowsFake $f) => $f->assertActivated('flow')],
        'assertRestored' => [fn () => Workflows::definitions()->restore('flow'), fn (WorkflowsFake $f) => $f->assertRestored('flow')],
        'assertDraftDiscarded' => [fn () => Workflows::definition('flow')->discardDraft(), fn (WorkflowsFake $f) => $f->assertDraftDiscarded('flow')],
        'assertFlagResolved' => [fn () => Workflows::definition('flow')->resolveFlag('notify', '/recipients'), fn (WorkflowsFake $f) => $f->assertFlagResolved('flow', node: 'notify', path: '/recipients')],
        'assertWebhookRotated' => [fn () => Workflows::definition('flow')->rotateWebhook(), fn (WorkflowsFake $f) => $f->assertWebhookRotated('flow')],
        'assertImported' => [fn () => Workflows::versions()->import(['definition' => ['key' => 'tpl', 'name' => 'T'], 'graph' => []]), fn (WorkflowsFake $f) => $f->assertImported('tpl')],
        'assertWebhookReceived' => [fn () => Workflows::triggers()->webhook('wh_1', []), fn (WorkflowsFake $f) => $f->assertWebhookReceived('wh_1')],
        'assertRetried' => [fn () => Workflows::instances()->retry(WorkflowInstance::query()->firstOrFail()), fn (WorkflowsFake $f) => $f->assertRetried(WorkflowInstance::query()->firstOrFail())],
        'assertCancelled' => [fn () => Workflows::instances()->cancel(WorkflowInstance::query()->firstOrFail()), fn (WorkflowsFake $f) => $f->assertCancelled(WorkflowInstance::query()->firstOrFail())],
        'assertWoken' => [fn () => Workflows::wake(), fn (WorkflowsFake $f) => $f->assertWoken(times: 1)],
        'assertPruned' => [fn () => Workflows::prune(), fn (WorkflowsFake $f) => $f->assertPruned()],
        'assertTouched' => [fn () => Workflows::for(Document::query()->firstOrFail())->touch(), fn (WorkflowsFake $f) => $f->assertTouched(Document::query()->firstOrFail())],
        'assertSynced' => [fn () => Workflows::definitions()->sync('/definitions'), fn (WorkflowsFake $f) => $f->assertSynced('/definitions', publish: false)],
    ];
}

/**
 * The negative twin of each assertion: it passes on silence and fails after the call.
 *
 * @return array<string, Closure(WorkflowsFake): mixed>
 */
function negativeFakeAssertions(): array
{
    return [
        'assertStarted' => fn (WorkflowsFake $f) => $f->assertNotStarted('flow'),
        'assertEmitted' => fn (WorkflowsFake $f) => $f->assertNothingEmitted(),
        'assertCreated' => fn (WorkflowsFake $f) => $f->assertNotCreated('onboarding'),
        'assertEdited' => fn (WorkflowsFake $f) => $f->assertNotEdited('flow'),
        'assertPublished' => fn (WorkflowsFake $f) => $f->assertNotPublished('flow'),
        'assertRetired' => fn (WorkflowsFake $f) => $f->assertNotRetired('flow'),
        'assertActivated' => fn (WorkflowsFake $f) => $f->assertNotActivated('flow'),
        'assertRestored' => fn (WorkflowsFake $f) => $f->assertNotRestored('flow'),
        'assertDraftDiscarded' => fn (WorkflowsFake $f) => $f->assertDraftNotDiscarded('flow'),
        'assertFlagResolved' => fn (WorkflowsFake $f) => $f->assertNoFlagResolved('flow'),
        'assertWebhookRotated' => fn (WorkflowsFake $f) => $f->assertWebhookNotRotated('flow'),
        'assertImported' => fn (WorkflowsFake $f) => $f->assertNothingImported(),
        'assertWebhookReceived' => fn (WorkflowsFake $f) => $f->assertWebhookNotReceived('wh_1'),
        'assertRetried' => fn (WorkflowsFake $f) => $f->assertNotRetried(WorkflowInstance::query()->firstOrFail()),
        'assertCancelled' => fn (WorkflowsFake $f) => $f->assertNotCancelled(WorkflowInstance::query()->firstOrFail()),
        'assertWoken' => fn (WorkflowsFake $f) => $f->assertNotWoken(),
        'assertPruned' => fn (WorkflowsFake $f) => $f->assertNotPruned(),
        'assertTouched' => fn (WorkflowsFake $f) => $f->assertNotTouched(Document::query()->firstOrFail()),
        'assertSynced' => fn (WorkflowsFake $f) => $f->assertNotSynced(),
    ];
}

it('has a passing and a failing case for every assertion', function (string $name): void {
    published('flow', fakeFlow(), null, null);
    WorkflowInstance::factory()->create();
    document();
    $fake = Workflows::fake();
    [$call, $assert] = fakeAssertions()[$name];
    $negative = negativeFakeAssertions()[$name];

    expect(fn () => $assert($fake))->toThrow(ExpectationFailedException::class);
    $negative($fake);
    $fake->assertNothingRecorded();

    $call();

    $assert($fake);
    expect(fn () => $negative($fake))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRecorded())->toThrow(ExpectationFailedException::class);
})->with(array_keys(fakeAssertions()));

it('names the key or instance in a failed assertion', function (): void {
    $instance = WorkflowInstance::factory()->create();
    $fake = Workflows::fake();

    expect(fn () => $fake->assertPublished('onboarding'))->toThrow(ExpectationFailedException::class, '[onboarding]')
        ->and(fn () => $fake->assertRetried($instance))->toThrow(ExpectationFailedException::class, $instance->ulid)
        ->and(fn () => $fake->assertImported())->toThrow(ExpectationFailedException::class, 'Expected a template to be imported')
        ->and(fn () => $fake->assertWoken(times: 2))->toThrow(ExpectationFailedException::class, 'woken 0 time(s)')
        ->and(fn () => $fake->assertTouched())->toThrow(ExpectationFailedException::class, 'Expected a subject to be touched');

    Workflows::for(document())->touch();

    expect(fn () => $fake->assertNotTouched())->toThrow(ExpectationFailedException::class, 'Expected no subject');
});

it('sees trait-made touches and cancels', function (): void {
    config()->set('workflows.subjects_on_deleted', 'cancel');
    $document = document();
    $other = document();
    $instance = WorkflowInstance::factory()->create(['subject_type' => $document->getMorphClass(), 'subject_id' => $document->id]);
    WorkflowWait::factory()->create(['instance_id' => $instance->id, 'kind' => WaitKind::Condition]);
    $fake = Workflows::fake();

    $document->update(['title' => 'Changed']);

    $fake->assertTouched($document);
    $fake->assertNotTouched($other);

    $document->delete();

    $fake->assertCancelled($instance);
});

it('covers every operation with an assertion', function (): void {
    $covered = [
        'create_definition' => ['assertCreated', 'assertNotCreated'],
        'restore_definition' => ['assertRestored', 'assertNotRestored'],
        'edit' => ['assertEdited', 'assertNotEdited'],
        'discard_draft' => ['assertDraftDiscarded', 'assertDraftNotDiscarded'],
        'publish' => ['assertPublished', 'assertNotPublished'],
        'retire' => ['assertRetired', 'assertNotRetired'],
        'activate' => ['assertActivated', 'assertNotActivated'],
        'resolve_flag' => ['assertFlagResolved', 'assertNoFlagResolved'],
        'rotate_webhook' => ['assertWebhookRotated', 'assertWebhookNotRotated'],
        'import' => ['assertImported', 'assertNothingImported'],
        'start' => ['assertStarted', 'assertNotStarted'],
        'emit' => ['assertEmitted', 'assertNothingEmitted'],
        'webhook' => ['assertWebhookReceived', 'assertWebhookNotReceived'],
        'retry' => ['assertRetried', 'assertNotRetried'],
        'cancel' => ['assertCancelled', 'assertNotCancelled'],
        'touch' => ['assertTouched', 'assertNotTouched'],
        'wake' => ['assertWoken', 'assertNotWoken'],
        'prune' => ['assertPruned', 'assertNotPruned'],
        'sync' => ['assertSynced', 'assertNotSynced'],
    ];
    $operations = array_values(array_diff(WorkflowOperation::values()->all(), ['signal', 'dry_run']));

    expect(array_keys($covered))->toEqualCanonicalizing($operations);

    foreach ($covered as $methods) {
        foreach ($methods as $method) {
            expect(method_exists(WorkflowsFake::class, $method))->toBeTrue($method);
        }
    }
});

it('builds graphs under the fake', function (): void {
    $fake = Workflows::fake();

    $graph = Workflows::graph()->trigger('start', Trigger::manual())->end('done')->chain('start', 'done');

    expect($graph->validate()->valid())->toBeTrue();
    $fake->assertNothingRecorded();
});

// W-14
it('scripts an outcome for a registered step type', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => 'Order']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);
    $this->fakeStep('acme.create_task', static fn (StepContext $context): StepResult => StepResult::complete(output: ['task_id' => 7]));

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(Task::query()->count())->toBe(0)
        ->and(Workflows::instances()->steps($instance)->firstWhere('node_id', 'task')?->output)->toBe(['task_id' => 7]);

    $this->assertStepRan('acme.create_task', times: 1, where: static fn (StepContext $context): bool => $context->config()['title'] === 'Order');
});

it('scripts an unregistered bridged type with declared outcomes', function (bool $queued): void {
    if ($queued) {
        config()->set('workflows.queue.enabled', 'on');
        config()->set('queue.default', 'database');
    }

    $this->fakeStep('approval', 'rejected', outcomes: ['approved', 'rejected', 'expired']);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'role', 'role' => 'legal']], 'rule' => 'any']),
        node('yes', 'end'),
        node('no', 'end', ['status' => 'failed', 'reason' => 'Rejected']),
    ], [['start', 'approve'], ['approve', 'yes', 'approved'], ['approve', 'no', 'rejected'], ['approve', 'no', 'expired']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $this->runWorkflows();

    expect($instance->refresh()->path())->toBe(['start', 'approve', 'no'])
        ->and($instance->failure())->toBe(FailureCode::EndedByGraph);

    $this->assertStepRan('approval', times: 1);
})->with(['inline' => [false], 'queued' => [true]]);

it('still authorizes a scripted side-effect step', function (): void {
    FixtureServiceProvider::$allow = false;
    $this->fakeStep('acme.create_task', 'next');
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => 'x']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    expect(Workflows::start('flow')->dispatch()->instance)->toHaveFailedWith('authorization_denied');

    $this->assertStepNotRan('acme.create_task');
});

it('restores the real step type after the test', function (): void {
    $original = app(StepCatalogue::class)->find('acme.create_task');
    $this->fakeStep('acme.create_task', 'next');
    $this->fakeStep('acme.create_task', 'next');
    $this->fakeStep('acme.unknown', 'next', outcomes: ['next']);

    expect(app(StepCatalogue::class)->find('acme.create_task'))->toBeInstanceOf(ScriptedStepType::class)
        ->and(app(StepCatalogue::class)->find('acme.create_task'))->not->toBe($original)
        ->and(fn () => $this->fakeStep('acme.nothing', 'next'))->toThrow(LogicException::class, 'pass the outcomes');

    $this->restoreSteps();

    expect(app(StepCatalogue::class)->find('acme.create_task'))->toBe($original)
        ->and(app(StepCatalogue::class)->find('acme.unknown'))->toBeNull();
});

it('asserts step runs with a where clause', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => 'Order']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);
    $this->fakeStep('acme.create_task', StepResult::complete());

    $this->assertStepNotRan('acme.create_task');
    expect(fn () => $this->assertStepRan('acme.create_task'))->toThrow(ExpectationFailedException::class);

    Workflows::start('flow')->dispatch();

    $this->assertStepRan('acme.create_task');
    expect(fn () => $this->assertStepRan('acme.create_task', where: static fn (StepContext $context): bool => $context->config()['title'] === 'Other'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->assertStepRan('acme.create_task', times: 2))->toThrow(ExpectationFailedException::class, 'ran 1 time(s)')
        ->and(fn () => $this->assertStepNotRan('acme.create_task'))->toThrow(ExpectationFailedException::class);

    $type = app(StepCatalogue::class)->find('acme.create_task') ?? throw new LogicException;

    expect($type->label())->toBe('Create task')
        ->and($type->category())->toBe('custom')
        ->and($type->description())->toBeNull()
        ->and($type->hasSideEffects())->toBeTrue()
        ->and($type->ability())->toBe('createTask')
        ->and($type->supportsEscalations())->toBeFalse()
        ->and($type->ownerSpecificPaths())->toBe([]);
});

it('travels to an absolute instant and wakes, then asserts where the instance stands', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('pause', 'delay', ['for' => 'PT2H']), node('done', 'end')], [['start', 'pause'], ['pause', 'done']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    $this->assertInstanceAt($instance, 'pause');
    $this->assertInstanceStatus($instance, InstanceStatus::Waiting);

    $this->travelAndWake(CarbonImmutable::now()->addHours(3));

    $this->assertInstanceStatus($instance, InstanceStatus::Completed);
    $this->assertPath($instance, ['start', 'pause', 'done']);

    expect(fn () => $this->assertInstanceAt($instance, 'start'))->toThrow(ExpectationFailedException::class, 'Expected the instance at [start]');

    Carbon\Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});
