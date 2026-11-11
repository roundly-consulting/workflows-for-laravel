<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Workflows\Actions\Engine\FireEscalationAction;
use RoundlyConsulting\Workflows\Actions\Engine\ResumeStepAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Exceptions\InvalidDefinitionException;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Exceptions\StepAuthorizationDeniedException;
use RoundlyConsulting\Workflows\Exceptions\UnknownSubjectTypeException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Http\Resources\Enums;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\KeyTypes;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\TriggerForms;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;

// W-07: the edge and error paths the main suites leave out.

function queuedSpy(): StepRun
{
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy'), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    config()->set('queue.default', 'sync');

    return StepRun::query()->where('instance_id', $instance->id)->where('node_id', 'spy')->firstOrFail();
}

it('refuses a form trigger when forms is missing at run time', function (): void {
    $forms = app(TriggerForms::class);

    expect($forms->available())->toBeFalse()
        ->and($forms->exists('purchase'))->toBeFalse()
        ->and(fn () => $forms->validate('purchase', [], null, false, null))->toThrow(InvalidTriggerInputException::class);
});

it('refuses a uuid subject on a bigint key type and accepts matching keys', function (): void {
    $uuidModel = new class extends Model
    {
        use HasUuids;
    };

    expect(fn () => KeyTypes::assertModel('subject', $uuidModel::class))->toThrow(InvalidWorkflowsConfigurationException::class, 'workflows.key_type');

    config()->set('workflows.subject_key_type', 'uuid');

    expect(fn () => KeyTypes::assertModel('subject', Document::class))->toThrow(InvalidWorkflowsConfigurationException::class, 'workflows.subject_key_type')
        ->and(fn () => KeyTypes::assertValue('subject', (new Document)->forceFill(['id' => 5])))->toThrow(InvalidWorkflowsConfigurationException::class);

    KeyTypes::assertModel('subject', $uuidModel::class);
    KeyTypes::assertValue('subject', (new $uuidModel)->forceFill(['id' => '0190a5c4-3f7e-7c4e-9a0b-6f1e2d3c4b5a']));

    config()->set('workflows.subject_key_type', 'ulid');

    expect(fn () => KeyTypes::assertModel('subject', $uuidModel::class))->toThrow(InvalidWorkflowsConfigurationException::class);

    KeyTypes::assertValue('subject', (new $uuidModel)->forceFill(['id' => '01JC9ZZZZZZZZZZZZZZZZZZZZZ']));
    KeyTypes::assertValue('subject', null);
});

it('generates ulid and uuid primary keys', function (string $type, Closure $valid): void {
    config()->set('workflows.primary_key_type', $type);
    $wait = new WorkflowWait;
    $id = $wait->newUniqueId();

    expect($valid($id))->toBeTrue()
        ->and($wait->usesUniqueIds())->toBeTrue()
        ->and((fn () => $this->isValidUniqueId($id))->call($wait))->toBeTrue()
        ->and((fn () => $this->isValidUniqueId('nope'))->call($wait))->toBeFalse();
})->with([
    'ulid' => ['ulid', fn (string $id): bool => Str::isUlid($id)],
    'uuid' => ['uuid', fn (string $id): bool => Str::isUuid($id)],
]);

it('validates wait configs', function (array $config, string $path): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', $config),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received'], ['wait', 'done', 'met']]), 'document');

    expect(collect($report->errorsOnly())->contains(fn ($error): bool => $error->node === 'wait' && $error->code === ValidationCode::InvalidConfig && ($error->path ?? '') === $path))->toBeTrue();
})->with([
    'event and condition' => [['event' => 'doc.signed', 'condition' => ['field' => 'subject.title', 'op' => 'empty']], ''],
    'bad event key' => [['event' => 'Not A Key'], '/event'],
    'poll under a minute' => [['condition' => ['field' => 'subject.title', 'op' => 'empty'], 'poll' => 'PT30S'], '/poll'],
    'poll in minutes' => [['condition' => ['field' => 'subject.title', 'op' => 'empty'], 'poll' => ['amount' => 0, 'unit' => 'minutes']], '/poll'],
    'business poll' => [['condition' => ['field' => 'subject.title', 'op' => 'empty'], 'poll' => ['amount' => 1, 'unit' => 'business_hours']], '/poll'],
]);

it('accepts polls in hours and days', function (array $poll): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['condition' => ['field' => 'subject.title', 'op' => 'empty'], 'poll' => $poll]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'met']]), 'document');

    expect($report->valid())->toBeTrue();
})->with([[['amount' => 1, 'unit' => 'hours']], [['amount' => 1, 'unit' => 'days']]]);

it('simulates a condition wait that already holds', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['condition' => ['field' => 'subject.title', 'op' => 'equals', 'value' => 'Ready'], 'poll' => 'PT1H']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'met']]));

    $result = Workflows::definition('flow')->dryRun()->sample(['title' => 'Ready'])->run();

    expect($result->nodes())->toBe(['start', 'wait', 'done'])
        ->and($result->path[1]->assumed)->toBeFalse();
});

it('reads an ancestor step output in a dry run', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('task', 'acme.create_task', ['title' => 'x']),
        node('check', 'condition', ['when' => ['field' => 'steps.task.output.task_id', 'op' => 'empty']]),
        node('done', 'end'),
    ], [['start', 'task'], ['task', 'check'], ['check', 'done', 'true'], ['check', 'done', 'false']]), null, null);

    expect(Workflows::definition('flow')->dryRun()->run()->path[2]->outcome)->toBe('true');
});

it('exposes the current version and drafts relations', function (): void {
    $definition = published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    Workflows::start('flow')->dispatch();

    expect($definition->versions)->toHaveCount(1)
        ->and($definition->instances)->toHaveCount(1)
        ->and($definition->currentVersion)->toBeInstanceOf(WorkflowVersion::class);
});

it('links child instances to their parent', function (): void {
    $parent = WorkflowInstance::factory()->create();
    $child = WorkflowInstance::factory()->create(['parent_instance_id' => $parent->id]);

    expect($child->parent?->is($parent))->toBeTrue()
        ->and($parent->children->first()?->is($child))->toBeTrue();
});

it('builds every exception factory with a translated message and code', function (Closure $build, string $code, int $status): void {
    $exception = $build();

    expect($exception->errorCode())->toBe($code)
        ->and($exception->status())->toBe($status)
        ->and($exception->getMessage())->not->toStartWith('exceptions.')
        ->and($exception->getMessage())->not->toContain('\\');
})->with([
    'invalid definition key' => [fn () => InvalidDefinitionException::key('Bad Key'), 'invalid_definition_key', 422],
    'subject-less definition' => [fn () => InvalidDefinitionException::subjectless('flow'), 'definition_needs_subject', 422],
    'authorization denied' => [fn () => StepAuthorizationDeniedException::because(null, 'update'), 'authorization_denied', 403],
    'authorization denied with a reason' => [fn () => StepAuthorizationDeniedException::because('Not yours.', 'update'), 'authorization_denied', 403],
    'unregistered subject model' => [fn () => UnknownSubjectTypeException::model('Spaceship'), 'unregistered_subject_model', 422],
]);

it('describes every kind of invalid configuration value', function (mixed $value, string $described): void {
    expect(InvalidWorkflowsConfigurationException::invalidDuration('workflows.engine.lease', $value)->getMessage())->toContain('['.$described.'] given');
})->with([
    'int' => [5, '5'],
    'float' => [1.5, '1.5'],
    'true' => [true, 'true'],
    'false' => [false, 'false'],
    'null' => [null, 'null'],
    'object' => [new Company, Company::class],
    'array' => [[1], 'array'],
]);

it('renders a missing enum as null', function (): void {
    expect(Enums::render(null))->toBeNull()
        ->and(Enums::render(InstanceStatus::Running)['value'] ?? null)->toBe('running');
});

it('treats a ref as unknown outside a node', function (): void {
    $scope = new ValidationScope(GraphParser::parse(graph([], [])), null, null, null, null);

    expect($scope->isAncestor('start'))->toBeFalse();
});

it('fails a run whose version is gone', function (): void {
    $run = queuedSpy();
    $instance = WorkflowInstance::query()->findOrFail($run->instance_id);
    Schema::disableForeignKeyConstraints();
    WorkflowInstance::query()->whereKey($instance->id)->update(['version_id' => 999999]);
    Schema::enableForeignKeyConstraints();

    app(RunStepAction::class)->execute($run->id);

    expect($instance->refresh()->failure())->toBe(FailureCode::VersionMissing)
        ->and($run->refresh()->input)->toBe([]);
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'needs the foreign key switch of SQLite');

it('fails a run whose node is no longer in the graph', function (): void {
    $run = queuedSpy();
    $run->node_id = 'ghost';
    $run->save();

    app(RunStepAction::class)->execute($run->id);

    expect(WorkflowInstance::query()->findOrFail($run->instance_id)->failure())->toBe(FailureCode::UnknownStepType)
        ->and($run->refresh()->error())->toBe(FailureCode::UnknownStepType);
});

it('fails a run of a bridged type whose package is missing at run time', function (): void {
    $this->fakeStep('approval', 'approved', outcomes: ['approved', 'rejected', 'expired']);
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'triggered_by']], 'rule' => 'any']),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    config()->set('queue.default', 'sync');
    app()->forgetInstance(StepCatalogue::class);

    app(RunStepAction::class)->execute(StepRun::query()->where('instance_id', $instance->id)->where('node_id', 'approve')->value('id'));

    expect($instance->refresh())->toHaveFailedWith('adapter_missing');
});

it('ignores a run that is gone, not queued, scheduled later or of a cancelled instance', function (): void {
    $run = queuedSpy();

    app(RunStepAction::class)->execute(missingId());

    $run->scheduled_for = CarbonImmutable::now()->addHour();
    $run->save();
    app(RunStepAction::class)->execute($run->id);

    expect($run->refresh()->status)->toBe(StepRunStatus::Queued);

    $run->scheduled_for = null;
    $run->status = StepRunStatus::Completed;
    $run->save();
    app(RunStepAction::class)->execute($run->id);

    WorkflowInstance::query()->whereKey($run->instance_id)->update(['status' => InstanceStatus::Cancelled->value]);
    $run->status = StepRunStatus::Queued;
    $run->save();
    app(RunStepAction::class)->execute($run->id);

    expect($run->refresh()->status)->toBe(StepRunStatus::Queued);
});

it('ignores a resume of a gone run and cancels the wait of a finished one', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'doc.signed']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $wait = WorkflowWait::query()->where('instance_id', $instance->id)->firstOrFail();

    app(ResumeStepAction::class)->execute(missingId(), null, SignalKind::Event);
    app(ResumeStepAction::class)->execute($wait->step_run_id, missingId(), SignalKind::Event);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Waiting);

    WorkflowInstance::query()->whereKey($instance->id)->update(['status' => InstanceStatus::Cancelled->value]);
    app(ResumeStepAction::class)->execute($wait->step_run_id, $wait->id, SignalKind::Event);

    expect($wait->refresh()->status)->toBe(WaitStatus::Cancelled);
});

it('cancels an escalation whose step or instance is gone', function (): void {
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $escalation = WorkflowEscalation::query()->where('instance_id', $instance->id)->firstOrFail();

    app(FireEscalationAction::class)->execute(missingId());
    WorkflowInstance::query()->whereKey($instance->id)->update(['status' => InstanceStatus::Cancelled->value]);
    app(FireEscalationAction::class)->execute($escalation->id);

    expect($escalation->refresh()->status)->toBe(EscalationStatus::Cancelled);
});

it('fails an escalation with an unknown action or past the nesting depth', function (array $action, string $code): void {
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $escalation = WorkflowEscalation::query()->where('instance_id', $instance->id)->firstOrFail();
    $escalation->actions = [$action];
    $escalation->save();
    config()->set('workflows.limits.max_depth', 0);

    app(FireEscalationAction::class)->execute($escalation->id);

    expect($escalation->refresh()->error_code)->toBe($code);
})->with([
    'unknown action' => [['type' => 'teleport'], 'unknown_escalation_action'],
    'too deep' => [['type' => 'start_workflow', 'definition' => 'chase'], 'nesting_depth_exceeded'],
]);
