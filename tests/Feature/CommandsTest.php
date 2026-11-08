<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Workflows\Actions\Wake\WakeAction;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Calendars\WeekdayCalendar;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Doctor;
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
    return array_map(static fn ($finding): string => $finding->level.' '.$finding->check, app(Doctor::class)->run());
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

it('refuses to install over existing tables, and publishes into a fresh app', function (): void {
    $this->artisan('workflows:install')->expectsOutputToContain('already exists')->assertFailed();

    foreach (['definitions', 'versions', 'instances', 'step_runs', 'waits', 'escalations', 'idempotency_keys', 'dry_runs'] as $table) {
        config()->set('workflows.tables.'.$table, 'fresh_'.$table);
    }

    $before = [...glob(config_path('*')) ?: [], ...glob(database_path('migrations/*')) ?: []];

    try {
        $this->artisan('workflows:install')->expectsOutputToContain("Schedule::command('workflows:wake')")->assertSuccessful();

        expect(config_path('workflows.php'))->toBeFile()
            ->and(glob(database_path('migrations/*_create_workflow_definitions_table.php')))->toHaveCount(1);
    } finally {
        foreach (array_diff([...glob(config_path('*')) ?: [], ...glob(database_path('migrations/*')) ?: []], $before) as $file) {
            @unlink($file);
        }
    }
});
