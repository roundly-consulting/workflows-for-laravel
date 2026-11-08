<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Events\InstanceCompleted;
use RoundlyConsulting\Workflows\Events\InstanceFailed;
use RoundlyConsulting\Workflows\Events\InstanceStarted;
use RoundlyConsulting\Workflows\Events\StepCompleted;
use RoundlyConsulting\Workflows\Events\StepFailed;
use RoundlyConsulting\Workflows\Events\StepStarted;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Exceptions\MissingTriggeringUserException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\SubjectTypeMismatchException;
use RoundlyConsulting\Workflows\Exceptions\TriggerMismatchException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Jobs\RunStepJob;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\FixtureServiceProvider;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Task;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\SpyStep;

function manual(array $nodes, array $edges, array $trigger = [], array $settings = []): array
{
    return graph([node('start', 'trigger', ['trigger' => 'manual', ...$trigger]), ...$nodes], $edges, $settings);
}

function spyFlow(array $config = [], array $settings = []): array
{
    return manual([node('spy', 'acme.spy', $config), node('done', 'end')], [['start', 'spy'], ['spy', 'done']], [], $settings);
}

it('runs every built-in pure step and records the path and step log', function (): void {
    $company = company();
    $document = document($company, ['amount' => '10', 'priority' => 'low']);

    published('flow', manual([
        node('pick', 'branch', ['cases' => [['outcome' => 'big', 'when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '100']]], 'default' => 'small']),
        node('mark', 'set_field', ['field' => 'subject.priority', 'value' => 'high']),
        node('again', 'loop', ['max' => 2]),
        node('done', 'end'),
    ], [['start', 'pick'], ['pick', 'done', 'big'], ['pick', 'mark', 'small'], ['mark', 'again'], ['again', 'mark', 'continue'], ['again', 'done', 'exhausted']]), $company);

    $instance = Workflows::start('flow', $document, $company)->dispatch()->instance ?? throw new LogicException;

    expect($instance)->toHaveCompletedVia(['start', 'pick', 'mark', 'again', 'mark', 'again', 'mark', 'again', 'done'])
        ->and($document->refresh()->priority)->toBe('high')
        ->and(Workflows::instances()->steps($instance)->pluck('outcome')->all())->toBe(['next', 'small', 'next', 'continue', 'next', 'continue', 'next', 'exhausted', 'end'])
        ->and(Workflows::instances()->steps($instance)->first()?->idempotency_key)->toStartWith('wf:'.strtolower($instance->ulid).':start:1')
        ->and($instance->steps_count)->toBe(9);
});

it('dispatches the instance and step events', function (): void {
    Event::fake([InstanceStarted::class, StepStarted::class, StepCompleted::class, InstanceCompleted::class]);
    published('flow', spyFlow(), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance;

    Event::assertDispatched(InstanceStarted::class, fn (InstanceStarted $event): bool => $event->actor->is($instance) && $event->runAs->isSystem());
    Event::assertDispatched(StepStarted::class, 1);
    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->stepRun->node_id === 'spy' && $event->outcome === 'next');
    Event::assertDispatched(InstanceCompleted::class);
});

// C-83: every event names the instance as the actor.
it('names the instance as the actor of every event', function (): void {
    Event::fake();
    published('flow', spyFlow(['mode' => 'fail']), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance;

    foreach ([InstanceStarted::class, StepStarted::class, StepFailed::class, InstanceFailed::class] as $class) {
        Event::assertDispatched($class, fn (object $event): bool => $event->actor->is($instance));
    }
});

// C-20
it('validates manual input', function (): void {
    published('flow', manual([node('done', 'end')], [['start', 'done']], ['inputs' => [
        ['name' => 'reason', 'type' => 'string', 'required' => true, 'rules' => ['max:10']],
        ['name' => 'count', 'type' => 'integer'],
        ['name' => 'level', 'type' => 'enum', 'options' => ['a', 'b']],
    ]]), null, null);

    try {
        Workflows::start('flow')->input(['reason' => str_repeat('x', 11), 'count' => 'many', 'level' => 'c'])->dispatch();
        $this->fail('Expected InvalidTriggerInputException.');
    } catch (InvalidTriggerInputException $exception) {
        expect(array_keys($exception->errors()))->toBe(['reason', 'count', 'level'])->and($exception->status())->toBe(422);
    }

    $instance = Workflows::start('flow')->input(['reason' => 'ok', 'count' => 3, 'level' => 'a', 'extra' => 'dropped'])->dispatch()->instance;

    expect($instance?->trigger_payload['input'])->toEqual(['reason' => 'ok', 'count' => 3, 'level' => 'a']);
});

// A-8 (start variant)
it('starts one instance per trigger idempotency key', function (): void {
    published('flow', spyFlow(), null, null);

    $first = Workflows::start('flow')->idempotencyKey('submit:1')->dispatch();
    $second = Workflows::start('flow')->idempotencyKey('submit:1')->dispatch();
    $other = Workflows::start('flow')->idempotencyKey('submit:2')->dispatch();
    $none = Workflows::start('flow')->dispatch();

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->instance?->is($first->instance))->toBeTrue()
        ->and($other->created)->toBeTrue()
        ->and($none->created)->toBeTrue()
        ->and(WorkflowInstance::query()->count())->toBe(3)
        ->and(SpyStep::$calls)->toBe(3);
});

// C-58
it('starts a new instance once a trigger key has expired', function (): void {
    published('flow', spyFlow(), null, null);

    $first = Workflows::start('flow')->idempotencyKey('k')->dispatch()->instance;
    $this->travel(31)->days();
    $second = Workflows::start('flow')->idempotencyKey('k')->dispatch()->instance;

    expect($second?->is($first))->toBeFalse();
});

it('refuses a start through the wrong trigger path', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'event', 'event' => 'doc.saved']), node('done', 'end')], [['start', 'done']]), null, null);

    Workflows::start('flow')->dispatch();
})->throws(TriggerMismatchException::class);

it('refuses a subject of another type, or none where one is needed', function (): void {
    published('flow', spyFlow());
    published('loose', spyFlow(), null, null);

    expect(fn () => Workflows::start('flow')->dispatch())->toThrow(SubjectTypeMismatchException::class)
        ->and(fn () => Workflows::start('flow', company())->dispatch())->toThrow(SubjectTypeMismatchException::class)
        ->and(fn () => Workflows::start('loose', document())->dispatch())->toThrow(SubjectTypeMismatchException::class);
});

// C-81
it('requires a triggering user for on_behalf_of', function (): void {
    published('flow', spyFlow([], ['actor' => 'on_behalf_of']), null, null);

    expect(fn () => Workflows::start('flow')->dispatch())->toThrow(MissingTriggeringUserException::class);

    $ada = user('Ada');
    $instance = Workflows::start('flow')->by($ada)->dispatch()->instance;

    expect($instance?->starter_id)->toBe($ada->id)
        ->and(SpyStep::$contexts[0]->actor()->user?->is($ada))->toBeTrue();
});

// C-24
it('starts nothing when the trigger condition is false', function (): void {
    published('flow', manual([node('done', 'end')], [['start', 'done']], [
        'inputs' => [['name' => 'urgent', 'type' => 'boolean']],
        'condition' => ['field' => 'input.urgent', 'op' => 'equals', 'value' => true],
    ]), null, null);

    $skipped = Workflows::start('flow')->input(['urgent' => false])->dispatch();
    $started = Workflows::start('flow')->input(['urgent' => true])->dispatch();

    expect($skipped->wasSkipped())->toBeTrue()->and($skipped->skipped)->toBe('condition_false')->and($skipped->instance)->toBeNull()
        ->and($started->created)->toBeTrue();
});

// A-11: the step limit stops a runaway loop.
it('stops a runaway loop at the step limit', function (): void {
    Event::fake([InstanceFailed::class]);
    published('flow', manual([
        node('spy', 'acme.spy'), node('again', 'loop', ['max' => 1000]), node('done', 'end'),
    ], [['start', 'spy'], ['spy', 'again'], ['again', 'spy', 'continue'], ['again', 'done', 'exhausted']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect($instance)->toHaveFailedWith('step_limit_exceeded')
        ->and($instance->steps_count)->toBe(200)
        ->and($instance->failure_message)->toBe('The workflow ran more than 200 steps.');

    Event::assertDispatched(InstanceFailed::class, fn (InstanceFailed $event): bool => $event->code === 'step_limit_exceeded');
});

it('lowers the step limit per graph', function (): void {
    published('flow', manual([
        node('spy', 'acme.spy'), node('again', 'loop', ['max' => 1000]), node('done', 'end'),
    ], [['start', 'spy'], ['spy', 'again'], ['again', 'spy', 'continue'], ['again', 'done', 'exhausted']], [], ['limits' => ['max_steps' => 10]]), null, null);

    expect(Workflows::start('flow')->dispatch()->instance?->steps_count)->toBe(10);
});

// A-11: nesting depth stops a self-starting workflow.
it('stops a self-starting workflow at the nesting depth', function (): void {
    published('flow', manual([
        node('again', 'start_workflow', ['definition' => 'flow', 'wait' => false]), node('done', 'end'),
    ], [['start', 'again'], ['again', 'done', 'started']]), null, null);

    Workflows::start('flow')->dispatch();

    $instances = WorkflowInstance::query()->orderBy('id')->get();

    expect($instances->pluck('depth')->all())->toBe([0, 1, 2, 3])
        ->and($instances->pluck('status')->map->value->all())->toBe(['completed', 'completed', 'completed', 'failed'])
        ->and($instances->last()?->failure_code)->toBe('nesting_depth_exceeded')
        ->and($instances->last()?->root_instance_id)->toBe($instances->first()?->id);
});

// A-11: the start rate.
it('fails the instance that exceeds the start rate, without counting refused ones', function (): void {
    config()->set('workflows.limits.max_starts_per_minute', 2);
    published('flow', spyFlow(), null, null);

    $results = [];
    for ($i = 0; $i < 4; $i++) {
        $results[] = Workflows::start('flow')->dispatch()->instance;
    }

    expect(array_map(static fn (?WorkflowInstance $instance): ?string => $instance?->status->value, $results))->toBe(['completed', 'completed', 'failed', 'failed'])
        ->and($results[2]?->failure_code)->toBe('start_rate_exceeded');

    $this->travel(61)->seconds();

    expect(Workflows::start('flow')->dispatch()->instance?->status)->toBe(InstanceStatus::Completed);

    config()->set('workflows.limits.max_starts_per_minute', 'off');

    expect(Workflows::start('flow')->dispatch()->instance?->status)->toBe(InstanceStatus::Completed);
});

// A-12 (without teams)
it('never reads or writes a subject of another owner', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    published('flow', manual([node('wait', 'wait', ['event' => 'doc.go']), node('spy', 'acme.spy'), node('done', 'end')], [['start', 'wait'], ['wait', 'spy', 'received'], ['spy', 'done']]), $acme);

    // Start refused, no instance and no idempotency row.
    expect(fn () => Workflows::start('flow', document($globex), $acme)->idempotencyKey('x')->dispatch())->toThrow(SubjectOwnerMismatchException::class)
        ->and(WorkflowInstance::query()->count())->toBe(0)
        ->and(DB::table('workflow_idempotency_keys')->count())->toBe(0);

    // The subject moves to another owner mid-run: the step fails before its handler runs.
    $document = document($acme);
    $instance = Workflows::start('flow', $document, $acme)->dispatch()->instance ?? throw new LogicException;
    $document->update(['company_id' => $globex->id]);

    // The emit now comes from Globex's side: it never reaches Acme's waiting instance (C-137).
    expect(Workflows::emit('doc.go', $document)->resumed)->toBe(0);

    // Resumed anyway (Acme's own signal), the step fails on the owner check, not in the handler.
    $wait = $instance->waits()->first() ?? throw new LogicException;
    app(EngineQueue::class)->resume($wait->step_run_id, $wait->id, SignalKind::Event);

    expect($instance->refresh())->toHaveFailedWith('owner_mismatch')
        ->and(SpyStep::$calls)->toBe(0);
});

// C-80
it('fails a denied side-effect step cleanly without running it', function (): void {
    FixtureServiceProvider::$allow = false;
    published('flow', manual([node('task', 'acme.create_task', ['title' => 'x']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = Workflows::instances()->steps($instance)->last();

    expect($instance)->toHaveFailedWith('authorization_denied')
        ->and($run?->error_retryable)->toBeFalse()
        ->and($run?->attempt)->toBe(1)
        ->and(Task::query()->count())->toBe(0);
});

it('uses the configured ability over the type\'s own', function (): void {
    config()->set('workflows.authorization.abilities', ['acme.create_task' => 'nobody-has-this']);
    published('flow', manual([node('task', 'acme.create_task', ['title' => 'x']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    expect(Workflows::start('flow')->dispatch()->instance)->toHaveFailedWith('authorization_denied');
});

// C-82: the system actor authorizes as the service account, else a null user (fail closed).
it('authorizes the system actor fail-closed', function (): void {
    Gate::define('strict', static fn (User $user): bool => true);
    config()->set('workflows.authorization.abilities', ['acme.create_task' => 'strict']);
    published('flow', manual([node('task', 'acme.create_task', ['title' => 'x']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    expect(Workflows::start('flow')->dispatch()->instance)->toHaveFailedWith('authorization_denied');

    $service = user('Service');
    app()->instance(ServiceUser::class, new ServiceUser($service));
    config()->set('workflows.actor.system_user', ServiceUser::class);

    expect(Workflows::start('flow')->dispatch()->instance?->status)->toBe(InstanceStatus::Completed);
});

final class ServiceUser
{
    public function __construct(private User $user) {}

    public function __invoke(): User
    {
        return $this->user;
    }
}

// C-05
it('fails an attempt whose output is not plain JSON or too large', function (array $config): void {
    published('flow', spyFlow($config), null, null);

    expect(Workflows::start('flow')->dispatch()->instance)->toHaveFailedWith('invalid_output');
})->with([
    'a model' => [['mode' => 'model']],
    'NAN' => [['mode' => 'nan']],
]);

it('fails an attempt whose output is over the byte cap', function (): void {
    config()->set('workflows.steps_output.max_bytes', 256);
    published('flow', manual([node('task', 'acme.create_task', ['title' => str_repeat('x', 200)]), node('mark', 'set_field', ['field' => 'subject.title', 'value' => str_repeat('y', 240)]), node('done', 'end')], [['start', 'task'], ['task', 'mark'], ['mark', 'done']]));

    expect(Workflows::start('flow', document())->dispatch()->instance)->toHaveFailedWith('invalid_output');
});

it('retries an unknown exception and stores its message for operators only', function (): void {
    published('flow', spyFlow(['mode' => 'throw']), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = Workflows::instances()->steps($instance)->firstWhere('node_id', 'spy');

    expect($run?->error_code)->toBe('exception')
        ->and($run?->error_retryable)->toBeTrue()
        ->and($run?->error_message)->toContain('App\Models\User')
        ->and($instance->status)->toBe(InstanceStatus::Running)
        ->and(Workflows::instances()->steps($instance)->where('node_id', 'spy')->where('attempt', 2)->first()?->status)->toBe(StepRunStatus::Queued);
});

it('fails for good on a handler refusal', function (): void {
    published('flow', spyFlow(['mode' => 'fail']), null, null);

    expect(Workflows::start('flow')->dispatch()->instance)->toHaveFailedWith('refused');
});

// C-96 (the writable / type half)
it('writes only writable fields with a value of their type', function (): void {
    $document = document();
    published('flow', manual([node('mark', 'set_field', ['field' => 'subject.title', 'value' => ['ref' => 'input.title']]), node('done', 'end')], [['start', 'mark'], ['mark', 'done']], [
        'inputs' => [['name' => 'title', 'type' => 'string']],
    ]));

    Workflows::start('flow', $document)->input(['title' => 'Renamed'])->dispatch();

    expect($document->refresh()->title)->toBe('Renamed');
});

// C-101
it('fails the instance from an end node', function (): void {
    published('flow', manual([node('done', 'end', ['status' => 'failed', 'reason' => 'Nothing to do.'])], [['start', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance;

    expect($instance)->toHaveFailedWith('ended_by_graph')->and($instance?->failure_message)->toBe('Nothing to do.');
});

// C-100
it('never starts a child twice when the start step is retried', function (): void {
    published('child', spyFlow(), null, null);
    published('parent', manual([node('kick', 'start_workflow', ['definition' => 'child', 'wait' => true]), node('done', 'end')], [
        ['start', 'kick'], ['kick', 'done', 'completed'], ['kick', 'done', 'failed'],
    ]), null, null);

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $kick = $parent->stepRuns()->where('node_id', 'kick')->first() ?? throw new LogicException;

    // A retried attempt of the same visit asks for the same child.
    $again = StepRun::query()->create([...$kick->only(['instance_id', 'node_id', 'node_type', 'visit', 'idempotency_key']), 'attempt' => 2, 'status' => StepRunStatus::Queued]);
    $parent->update(['status' => InstanceStatus::Running]);
    app(RunStepAction::class)->execute($again->id);

    expect(WorkflowInstance::query()->whereNotNull('parent_instance_id')->count())->toBe(1)
        ->and($parent->refresh()->status)->toBe(InstanceStatus::Completed);
});

it('waits for a child and takes its outcome', function (): void {
    published('child', manual([node('wait', 'wait', ['event' => 'child.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    published('failing', manual([node('done', 'end', ['status' => 'failed'])], [['start', 'done']]), null, null);
    published('parent', manual([node('kick', 'start_workflow', ['definition' => 'child', 'wait' => true]), node('ok', 'end'), node('bad', 'end', ['status' => 'failed'])], [
        ['start', 'kick'], ['kick', 'ok', 'completed'], ['kick', 'bad', 'failed'],
    ]), null, null);
    published('other', manual([node('kick', 'start_workflow', ['definition' => 'failing', 'wait' => true]), node('ok', 'end'), node('bad', 'end')], [
        ['start', 'kick'], ['kick', 'ok', 'completed'], ['kick', 'bad', 'failed'],
    ]), null, null);

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;

    expect($parent)->toBeWaitingAt('kick');

    Workflows::emit('child.go');

    expect($parent->refresh())->toHaveCompletedVia(['start', 'kick', 'ok'])
        ->and(Workflows::start('other')->dispatch()->instance)->toHaveCompletedVia(['start', 'kick', 'bad']);
});

// C-85
it('refuses a child subject or a child definition of another owner', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    $mine = document($acme, ['requester_id' => user()->id]);

    published('child', spyFlow(), $acme);
    published('elsewhere', spyFlow(), $globex);
    published('parent', manual([node('kick', 'start_workflow', ['definition' => 'child', 'subject' => ['field' => 'subject.requester'], 'wait' => false]), node('done', 'end')], [['start', 'kick'], ['kick', 'done', 'started']]), $acme);
    $reach = manual([node('kick', 'start_workflow', ['definition' => 'elsewhere', 'wait' => false]), node('done', 'end')], [['start', 'kick'], ['kick', 'done', 'started']]);

    // Publish refuses a target that only another owner has.
    expect(Workflows::versions()->validate($reach, 'document')->has(ValidationCode::UnknownDefinition, 'kick'))->toBeTrue();

    // At run time too: the app-wide target is gone, and Globex's is never reached.
    published('elsewhere', spyFlow(), null);
    published('reach', $reach, null);
    Workflows::definitions()->find('elsewhere')?->delete();

    // The requester belongs to no owner of Acme's: refused before any child exists.
    expect(Workflows::start('parent', $mine, $acme)->dispatch()->instance)->toHaveFailedWith('owner_mismatch')
        ->and(WorkflowInstance::query()->whereNotNull('parent_instance_id')->count())->toBe(0);

    // An app-wide parent cannot reach Globex's definition.
    expect(Workflows::start('reach', document($acme))->dispatch()->instance)->toHaveFailedWith('unknown_definition');
});

// C-41 / C-42 / C-43
it('does nothing for a queued step of a cancelled instance or a duplicate delivery', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    published('flow', spyFlow(), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = $instance->stepRuns()->where('node_id', 'spy')->first() ?? throw new LogicException;

    app(RunStepAction::class)->execute($run->id);
    app(RunStepAction::class)->execute($run->id);

    expect(SpyStep::$calls)->toBe(1);

    $second = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::instances()->cancel($second);
    app(RunStepAction::class)->execute($second->stepRuns()->where('node_id', 'spy')->first()?->id ?? 0);

    expect(SpyStep::$calls)->toBe(1)->and(DB::table('jobs')->count())->toBeGreaterThan(0);
});

it('discards a result whose claim was lost', function (): void {
    published('flow', manual([node('wait', 'wait', ['event' => 'doc.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = $instance->stepRuns()->where('node_id', 'wait')->first() ?? throw new LogicException;
    $run->update(['status' => StepRunStatus::Running, 'claim_token' => 'NEWTOKEN']);

    app(RecordStepResultAction::class)->execute($instance->id, $run->id, 'OLDTOKEN', StepResult::complete('received'));

    expect($run->refresh()->status)->toBe(StepRunStatus::Running)->and($instance->refresh()->status)->toBe(InstanceStatus::Waiting);
});

// C-140
it('never fails a still-running attempt from a redelivered job', function (): void {
    published('flow', manual([node('wait', 'wait', ['event' => 'doc.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = $instance->stepRuns()->where('node_id', 'wait')->first() ?? throw new LogicException;
    $run->update(['status' => StepRunStatus::Running, 'claim_token' => 'LIVE', 'lease_until' => Clock::now()->addMinutes(9)]);

    (new RunStepJob($run->id))->failed(new RuntimeException('timeout'));

    expect($run->refresh()->status)->toBe(StepRunStatus::Running);

    $run->update(['lease_until' => Clock::now()->subMinute()]);
    (new RunStepJob($run->id))->failed(new RuntimeException('timeout'));

    expect($run->refresh()->status)->toBe(StepRunStatus::Failed)
        ->and($run->error_code)->toBe('worker_timeout')
        ->and(app(RecordStepResultAction::class)->failExpired($run->id, StepFailure::transient('x', 'y')))->toBeFalse();
});

// C-141
it('runs 200 steps inline without nesting the call stack', function (): void {
    $depths = [];
    SpyStep::$contexts = [];
    published('flow', manual([
        node('spy', 'acme.spy'), node('again', 'loop', ['max' => 98]), node('done', 'end'),
    ], [['start', 'spy'], ['spy', 'again'], ['again', 'spy', 'continue'], ['again', 'done', 'exhausted']]), null, null);

    Event::listen(StepStarted::class, static function () use (&$depths): void {
        $depths[] = count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    });

    $instance = Workflows::start('flow')->dispatch()->instance;

    expect($instance?->status)->toBe(InstanceStatus::Completed)
        ->and(SpyStep::$calls)->toBe(99)
        ->and(max($depths) - min($depths))->toBeLessThan(5);
});

// C-40 / C-45
it('locks the instance before the step run on claim and record', function (): void {
    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();

    published('flow', spyFlow(), null, null);
    Workflows::start('flow')->dispatch();

    $locks = array_values(array_filter(LockRecorder::recorded(), static fn (array $lock): bool => str_contains($lock['sql'], 'workflow_instances') || str_contains($lock['sql'], 'workflow_step_runs')));
    $tables = array_map(static fn (array $lock): string => str_contains($lock['sql'], 'workflow_instances') ? 'instance' : 'step', $locks);

    expect(array_slice($tables, 0, 4))->toBe(['instance', 'step', 'instance', 'step'])
        ->and(min(array_column($locks, 'transactionDepth')))->toBeGreaterThanOrEqual(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'the recording grammar is SQLite only');

// C-111
it('touches a subject only when it has condition waits', function (): void {
    $document = document();
    published('flow', manual([node('wait', 'wait', ['condition' => ['field' => 'subject.priority', 'op' => 'equals', 'value' => 'high']]), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'met']]));

    $fake = Workflows::fake()->passthrough();
    $document->update(['title' => 'no instance yet']);
    $fake->assertNothingStarted();

    expect($fake->recorded(WorkflowOperation::Touch))->toBe([]);

    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;

    expect($instance)->toBeWaitingAt('wait');

    $document->update(['priority' => 'high']);

    $fake->assertTouched();
    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

// C-112
it('cancels the instances of a deleted subject, or keeps them', function (): void {
    published('flow', manual([node('wait', 'wait', ['event' => 'doc.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]));

    $gone = document();
    $first = Workflows::start('flow', $gone)->dispatch()->instance;
    $gone->delete();

    config()->set('workflows.subjects_on_deleted', 'keep');
    $kept = document();
    $second = Workflows::start('flow', $kept)->dispatch()->instance;
    $kept->delete();

    expect($first?->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and($second?->refresh()->status)->toBe(InstanceStatus::Waiting);
});

// W-03: every attempt records the config it saw, with each reference resolved.
it('records the resolved config as each attempt\'s input', function (): void {
    $acme = company();
    $ada = user('Ada');
    $document = document($acme, ['amount' => '120.5', 'requester_id' => $ada->id]);
    published('flow', spyFlow([
        'mode' => 'ok',
        'label' => 'Order',
        'amount' => ['ref' => 'subject.amount'],
        'title' => ['ref' => 'input.title'],
        'who' => [['kind' => 'field', 'field' => 'subject.requester'], ['kind' => 'role', 'role' => 'legal']],
    ]), $acme);

    $instance = Workflows::start('flow', $document, $acme)->input(['title' => 'Laptops'])->dispatch()->instance ?? throw new LogicException;
    $runs = Workflows::instances()->steps($instance)->keyBy('node_id');

    expect($runs['spy']->input)->toBe([
        'mode' => 'ok',
        'label' => 'Order',
        'amount' => '120.5',
        'title' => 'Laptops',
        'who' => [['kind' => 'field', 'field' => 'subject.requester', 'id' => $ada->id, 'type' => 'User'], ['kind' => 'role', 'role' => 'legal']],
    ])->and($runs['start']->input)->toBe(['input' => ['title' => 'Laptops'], 'trigger' => []])
        ->and($runs['done']->input)->toBe([])
        ->and(Workflows::instances()->steps($instance)->whereNull('input'))->toBeEmpty();
});

it('renders models in a step input without class names', function (): void {
    $document = document(null, ['requester_id' => user()->id]);
    published('flow', spyFlow(['requester' => ['ref' => 'subject.requester'], 'by' => [['kind' => 'triggered_by']]]));

    $instance = Workflows::start('flow', $document)->by(user('Bob'))->dispatch()->instance ?? throw new LogicException;
    $input = Workflows::instances()->steps($instance)->firstWhere('node_id', 'spy')?->input;
    $json = (string) json_encode($input);

    expect($input['requester'] ?? null)->toMatchArray(['type' => 'User'])
        ->and($input['by'][0] ?? null)->toMatchArray(['kind' => 'triggered_by', 'type' => 'User'])
        ->and($json)->not->toContain('\\\\')
        ->and($json)->not->toContain('Fixtures');
});

it('truncates an oversized step input', function (): void {
    config()->set('workflows.steps_input.max_bytes', 1024);
    published('flow', spyFlow(['blob' => str_repeat('x', 2000)]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $input = Workflows::instances()->steps($instance)->firstWhere('node_id', 'spy')?->input;

    expect($input['truncated'] ?? null)->toBeTrue()
        ->and($input['bytes'] ?? 0)->toBeGreaterThan(2000);
});

it('re-resolves input on a retry attempt', function (): void {
    $document = document(null, ['amount' => '10']);
    published('flow', spyFlow(['mode' => 'throw', 'amount' => ['ref' => 'subject.amount']]));

    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;
    $document->update(['amount' => '20']);
    $this->travelAndWake('PT1H');
    $attempts = Workflows::instances()->steps($instance)->where('node_id', 'spy')->values();

    expect($attempts->count())->toBeGreaterThanOrEqual(2)
        ->and($attempts[0]->input['amount'] ?? null)->toBe('10')
        ->and($attempts[1]->input['amount'] ?? null)->toBe('20');
});

it('records the unresolved config when the subject is gone', function (): void {
    $document = document();
    published('flow', spyFlow(['amount' => ['ref' => 'subject.amount']]));
    config()->set('workflows.subjects_on_deleted', 'keep');
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');

    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;
    $document->forceDelete();
    config()->set('queue.default', 'sync');
    $run = Workflows::instances()->steps($instance)->firstWhere('node_id', 'spy') ?? throw new LogicException;
    app(RunStepAction::class)->execute($run->id);

    expect($run->refresh()->input)->toBe(['amount' => ['ref' => 'subject.amount']])
        ->and($instance->refresh())->toHaveFailedWith('subject_missing');
});
