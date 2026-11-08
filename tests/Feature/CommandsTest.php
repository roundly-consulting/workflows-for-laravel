<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Workflows\Actions\Wake\WakeAction;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Calendars\WeekdayCalendar;
use RoundlyConsulting\Workflows\Enums\DoctorLevel;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Doctor;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Events\DocumentSigned;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\SpyStep;

function waitingFlow(): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'go.on']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]);
}

function doctorChecks(): array
{
    return array_map(static fn ($finding): string => $finding->level->value.' '.$finding->check, app(Doctor::class)->run());
}

it('wakes due work and prints the report', function (): void {
    $this->artisan('workflows:wake')
        ->expectsTable(['What', 'Count'], [['timers', '0'], ['retries', '0'], ['timeouts', '0'], ['polls', '0'], ['escalations', '0'], ['schedules', '0'], ['reclaimed', '0'], ['requeued', '0']])
        ->assertSuccessful();

    expect(Cache::get(WakeAction::LAST_RUN))->toBeString();
});

// C-127
it('prunes and prints what it pruned', function (): void {
    $this->artisan('workflows:prune')->expectsOutput('Pruned 0 instance(s), 0 idempotency key(s), 0 dry run(s).')->assertSuccessful();
});

it('lists the step catalogue', function (): void {
    $this->artisan('workflows:catalogue')
        ->expectsOutputToContain('set_field')
        ->expectsOutputToContain('acme.create_task')
        ->assertSuccessful();
});

it('cancels an instance, or explains why it cannot', function (): void {
    published('flow', waitingFlow(), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    $this->artisan('workflows:cancel', ['instance' => 'nope'])->expectsOutput('Instance not found.')->assertFailed();
    $this->artisan('workflows:cancel', ['instance' => $instance->ulid, '--reason' => 'ops'])->expectsOutput('Cancelled.')->assertSuccessful();

    expect($instance->refresh()->status)->toBe(InstanceStatus::Cancelled);

    $this->artisan('workflows:cancel', ['instance' => (string) $instance->id])->assertFailed();
});

it('retries a failed instance, or explains why it cannot', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy', ['mode' => 'fail']), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    published('ok', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    $completed = Workflows::start('ok')->dispatch()->instance ?? throw new LogicException;

    $this->artisan('workflows:retry', ['instance' => 'nope'])->expectsOutput('Instance not found.')->assertFailed();
    $this->artisan('workflows:retry', ['instance' => $instance->ulid])->expectsOutput('Retried.')->assertSuccessful();

    expect(SpyStep::$calls)->toBe(2);

    $this->artisan('workflows:retry', ['instance' => $completed->ulid])->assertFailed();
});

it('validates a definition, its draft, and an owner-scoped one', function (): void {
    published('flow', waitingFlow(), null, null);
    $company = company();
    published('scoped', waitingFlow(), $company, null);

    $this->artisan('workflows:validate', ['key' => 'flow'])->expectsOutput('Valid.')->assertSuccessful();
    $this->artisan('workflows:validate', ['key' => 'scoped', '--owner-type' => Company::class, '--owner-id' => (string) $company->id])->expectsOutput('Valid.')->assertSuccessful();
    $this->artisan('workflows:validate', ['key' => 'scoped', '--owner-type' => Company::class, '--owner-id' => '999'])->expectsOutput('Owner not found.')->assertFailed();
    $this->artisan('workflows:validate', ['key' => 'missing'])->assertFailed();

    Workflows::definition('flow')->edit(graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end'), node('lost', 'end')], [['start', 'done']]));

    $this->artisan('workflows:validate', ['key' => 'flow', '--draft' => true])->expectsOutputToContain('unreachable')->assertFailed();
    $this->artisan('workflows:validate', ['key' => 'flow'])->expectsOutput('Valid.')->assertSuccessful();

    Workflows::definitions()->create('fresh', 'Fresh');
    Workflows::definition('fresh')->edit(waitingFlow());

    $this->artisan('workflows:validate', ['key' => 'fresh'])->expectsOutput('Valid.')->assertSuccessful();
});

// C-134
it('diagnoses the installation', function (): void {
    expect(doctorChecks())->toContain('warn queue', 'warn wake', 'ok key types', 'ok step types', 'ok bridges');

    $this->artisan('workflows:doctor')->expectsOutputToContain('WARN')->assertSuccessful();
});

it('flags a queue whose retry_after is shorter than the step timeout', function (): void {
    config()->set('workflows.queue.enabled', true);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 30);
    config()->set('cache.default', 'array');
    Cache::put(WakeAction::LAST_RUN, CarbonImmutable::now()->subHour()->toIso8601String());

    expect(doctorChecks())->toContain('fail queue timeout', 'warn cache', 'warn wake');

    $this->artisan('workflows:doctor')->assertFailed();

    config()->set('queue.default', 'sync');
    config()->set('queue.connections.sync.retry_after', null);
    Cache::put(WakeAction::LAST_RUN, CarbonImmutable::now()->toIso8601String());

    expect(doctorChecks())->toContain('warn queue', 'ok wake');

    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'retry_after' => 600]);

    expect(doctorChecks())->toContain('warn cache')->not->toContain('fail queue timeout', 'warn queue');
});

it('flags definitions that need a missing calendar or bridge, and event mappings', function (): void {
    Workflows::calendars()->register('office', static fn () => new WeekdayCalendar('UTC'));
    published('cal', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']], ['calendar' => 'office']), null, null);
    app()->forgetInstance(CalendarRegistry::class);

    config()->set('workflows.events', [
        'App\\Events\\Missing' => 'x.missing',
        Login::class => 'user.login',
    ]);
    published('login', graph([node('start', 'trigger', ['trigger' => 'event', 'event' => 'user.login']), node('done', 'end')], [['start', 'done']], ['actor' => 'on_behalf_of']), null, null);
    config()->set('workflows.webhooks.enabled', 'on');

    $checks = doctorChecks();

    expect($checks)->toContain('fail calendar', 'fail events', 'warn events', 'fail webhooks');

    config()->set('workflows.webhooks.verify_middleware', ['auth']);

    expect(doctorChecks())->toContain('ok webhooks');
});

// W-05b
it('does not warn about idempotency for self-describing events', function (): void {
    config()->set('workflows.events', [DocumentSigned::class => '*']);

    $findings = array_values(array_filter(app(Doctor::class)->run(), static fn ($finding): bool => $finding->check === 'events'));

    expect($findings)->toBe([]);

    config()->set('workflows.events', [Login::class => 'user.login']);

    expect(doctorChecks())->toContain('warn events');
});

// W-12: doctor v2, each check red and green.
it('fails on an active version using an unregistered step type', function (): void {
    published('task', graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => 'x']), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    expect(doctorChecks())->not->toContain('fail step types');

    app()->forgetInstance(StepCatalogue::class);

    expect(doctorChecks())->toContain('fail step types');
});

it('fails on an invalid host config schema', function (): void {
    $type = new class extends StepType
    {
        public static bool $broken = false;

        public function key(): string
        {
            return 'acme.shifty';
        }

        public function label(): string
        {
            return 'Shifty';
        }

        public function hasSideEffects(): bool
        {
            return false;
        }

        public function configSchema(): array
        {
            return self::$broken ? ['type' => 'object', 'patternProperties' => []] : ['type' => 'object'];
        }

        public function handle(StepContext $context): StepResult
        {
            return StepResult::complete();
        }
    };
    Workflows::catalogue()->register($type);

    expect(doctorChecks())->not->toContain('fail config schema');

    $type::$broken = true;

    expect(doctorChecks())->toContain('fail config schema')
        ->and(collect(app(Doctor::class)->run())->firstWhere('check', 'config schema')?->message)->toContain('acme.shifty');
});

it('warns about condition waits on a subject without HasWorkflows', function (): void {
    $wait = static fn (string $field): array => graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['condition' => ['field' => 'subject.'.$field, 'op' => 'empty'], 'poll' => 'PT1H']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'met']]);
    published('doc', $wait('title'));

    expect(doctorChecks())->not->toContain('warn condition waits');

    published('company', $wait('plan'), null, 'company');

    expect(doctorChecks())->toContain('warn condition waits');
});

it('warns about stuck instances', function (): void {
    $instance = WorkflowInstance::factory()->create(['status' => InstanceStatus::Running, 'updated_at' => now()->subDay()]);
    StepRun::factory()->create(['instance_id' => $instance->id, 'status' => StepRunStatus::Queued]);

    expect(doctorChecks())->not->toContain('warn stuck');

    StepRun::query()->update(['status' => StepRunStatus::Completed->value]);

    expect(doctorChecks())->toContain('warn stuck');
});

it('warns about a wake backlog', function (): void {
    WorkflowWait::factory()->create(['due_at' => now()->addHour()]);

    expect(doctorChecks())->not->toContain('warn wake backlog');

    WorkflowWait::factory()->create(['due_at' => now()->subMinutes(10)]);

    expect(doctorChecks())->toContain('warn wake backlog');
});

it('counts the failures of the last day', function (): void {
    WorkflowInstance::factory()->create(['status' => InstanceStatus::Failed, 'ended_at' => now()->subHour()]);
    WorkflowInstance::factory()->create(['status' => InstanceStatus::Failed, 'ended_at' => now()->subDays(2)]);

    expect(collect(app(Doctor::class)->run())->firstWhere('check', 'failures')?->message)->toBe('1 instance(s) failed in the last 24 hours.');
});

it('prints findings as json', function (): void {
    expect(Artisan::call('workflows:doctor', ['--json' => true]))->toBe(0);

    $findings = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($findings)->toContain(['level' => 'warn', 'check' => 'queue', 'message' => 'Queue off: steps run inline in the request that starts them.'])
        ->and(array_keys($findings[0]))->toBe(['level', 'check', 'message'])
        ->and(DoctorLevel::Warn->label())->toBe('Warning');
});

it('exits non-zero on warnings under --strict', function (): void {
    $this->artisan('workflows:doctor')->assertSuccessful();
    $this->artisan('workflows:doctor', ['--strict' => true])->assertFailed();
});

// W-16
it('lists failed instances of a definition', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy', ['mode' => 'fail']), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);
    published('other', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    $failed = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $completed = Workflows::start('other')->dispatch()->instance ?? throw new LogicException;
    $acme = company();

    expect(Artisan::call('workflows:list', ['--status' => 'failed', '--definition' => 'flow']))->toBe(0)
        ->and(Artisan::output())->toContain($failed->ulid, 'refused', 'flow')->not->toContain($completed->ulid)
        ->and(Artisan::call('workflows:list', ['--limit' => 1]))->toBe(0)
        ->and(Artisan::output())->toContain($completed->ulid)->not->toContain($failed->ulid);

    $this->artisan('workflows:list', ['--owner-type' => $acme->getMorphClass(), '--owner-id' => (string) $acme->id])->expectsOutput('No instances.')->assertSuccessful();
    $this->artisan('workflows:list', ['--owner-type' => 'nope', '--owner-id' => '1'])->expectsOutput('Owner not found.')->assertFailed();
    $this->artisan('workflows:list', ['--status' => 'lost'])->expectsOutputToContain('Unknown status')->assertFailed();

    expect(Workflows::instances()->recent(definition: 'flow', owner: $acme))->toBeEmpty();
});

it('shows an instance with its step log, waits and escalation', function (): void {
    $document = document(company());
    published('flow', escalatingWait());
    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;

    expect(Artisan::call('workflows:show', ['instance' => $instance->ulid]))->toBe(0)
        ->and(Artisan::output())->toContain('waiting', 'Document#'.$document->id, 'Company#', 'Path: start → wait', 'doc.signed', 'timeout', 'Next escalation: level 1')
        ->not->toContain('Fixtures');

    expect(Artisan::call('workflows:show', ['instance' => $instance->ulid, '--json' => true]))->toBe(0);

    $detail = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($detail['steps'], 'node'))->toBe(['start', 'wait'])
        ->and($detail['waits'])->toHaveCount(2)
        ->and($detail['escalations'][0]['level'])->toBe(1);
});

it('shows nothing for an unknown instance and exits 1', function (): void {
    $this->artisan('workflows:show', ['instance' => '01JC9ZZZZZZZZZZZZZZZZZZZZZ'])->expectsOutput('Instance not found.')->assertFailed();
});
