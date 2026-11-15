<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Exceptions\OwnerPausedException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures\Tenant;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function pause(Tenant $tenant, bool $paused = true): void
{
    $tenant->forceFill(['paused' => $paused])->save();
}

/**
 * A manual flow that waits an hour, then ends.
 *
 * @return array<string, mixed>
 */
function hourLater(): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('later', 'delay', ['for' => 'PT1H']),
        node('done', 'end'),
    ], [['start', 'later'], ['later', 'done']]);
}

it('refuses every start for a paused owner and starts again once it is resumed', function (): void {
    $acme = tenant('Acme');
    published('flow', hourLater(), $acme, 'ticket');
    published('on-event', graph([node('start', 'trigger', ['trigger' => 'event', 'event' => 'ticket.opened']), node('done', 'end')], [['start', 'done']]), $acme, 'ticket');
    $ticket = ticket($acme);
    pause($acme);

    $emitted = Workflows::emit('ticket.opened', $ticket);

    expect(fn () => Workflows::start('flow', $ticket, $acme)->dispatch())->toThrow(OwnerPausedException::class, 'its owner is paused')
        ->and($emitted->started)->toBe([])
        ->and($emitted->failed[0]->exception->errorCode() ?? null)->toBe('owner_paused')
        ->and(WorkflowInstance::query()->count())->toBe(0)
        ->and(Workflows::owners()->isPaused($acme))->toBeTrue();

    try {
        Workflows::start('flow', $ticket, $acme)->dispatch();
    } catch (OwnerPausedException $exception) {
        expect($exception->status())->toBe(423)
            ->and($exception->context())->toBe(['code' => 'owner_paused', 'key' => 'flow']);
    }

    pause($acme, false);

    expect(Workflows::start('flow', $ticket, $acme)->dispatch()->created)->toBeTrue()
        ->and(Workflows::emit('ticket.opened', $ticket)->started)->toHaveCount(1)
        ->and(Workflows::owners()->isPaused($acme))->toBeFalse();
});

it('holds a paused owner\'s due waits and escalations, then fires each once after the pause', function (): void {
    $acme = tenant('Acme');
    $globex = tenant('Globex');
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme, null);

    foreach ([$acme, $globex] as $tenant) {
        published('flow', hourLater(), $tenant, 'ticket');
    }

    published('watch', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'ticket.closed'], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), $acme, 'ticket');

    $paused = Workflows::start('flow', ticket($acme), $acme)->dispatch()->instance ?? throw new LogicException;
    $active = Workflows::start('flow', ticket($globex), $globex)->dispatch()->instance ?? throw new LogicException;
    Workflows::start('watch', ticket($acme), $acme)->dispatch();
    pause($acme);

    $this->travelAndWake('PT2H');
    $this->travelAndWake('PT2H');

    expect($active->refresh())->toHaveCompletedVia(['start', 'later', 'done'])
        ->and($paused->refresh())->toBeWaitingAt('later')
        ->and(WorkflowEscalation::query()->sole()->status)->toBe(EscalationStatus::Pending)
        ->and(Workflows::definition('chase', $acme)->model()->instances()->count())->toBe(0);

    pause($acme, false);
    $first = Workflows::wake();
    $second = Workflows::wake();

    expect($paused->refresh())->toHaveCompletedVia(['start', 'later', 'done'])
        ->and($paused->stepRuns()->where('node_id', 'later')->count())->toBe(1)
        ->and($first->timers)->toBe(1)
        ->and($first->escalations)->toBe(1)
        ->and($second->timers + $second->escalations)->toBe(0)
        ->and(WorkflowEscalation::query()->sole()->status)->toBe(EscalationStatus::Fired)
        ->and(Workflows::definition('chase', $acme)->model()->instances()->count())->toBe(1);
});

it('skips a paused owner\'s schedule slots and never replays them', function (): void {
    $acme = tenant('Acme');
    published('tick', graph([node('start', 'trigger', ['trigger' => 'schedule', 'cron' => '*/10 * * * *', 'timezone' => 'UTC']), node('done', 'end')], [['start', 'done']]), $acme, null);
    pause($acme);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:10', 'UTC'));
    $paused = Workflows::wake()->schedules;
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:35', 'UTC'));
    $stillPaused = Workflows::wake()->schedules;

    pause($acme, false);
    $resumed = Workflows::wake()->schedules;
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:40', 'UTC'));
    $next = Workflows::wake()->schedules;

    expect([$paused, $stillPaused, $resumed, $next])->toBe([0, 0, 0, 1])
        ->and(WorkflowInstance::query()->sole()->trigger_payload['trigger']['slot'] ?? null)->toBe('2026-11-16T08:40');
});

it('refuses to retry a paused owner\'s failed instance', function (): void {
    $acme = tenant('Acme');
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy', ['mode' => 'fail']), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), $acme, 'ticket');
    $instance = Workflows::start('flow', ticket($acme), $acme)->dispatch()->instance ?? throw new LogicException;
    pause($acme);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Failed)
        ->and(fn () => Workflows::instances()->retry($instance))->toThrow(OwnerPausedException::class);

    pause($acme, false);
    Workflows::instances()->retry($instance);

    expect($instance->stepRuns()->where('node_id', 'spy')->count())->toBe(2);
});

it('retries a child start refused mid-run and starts it once the pause ends', function (): void {
    $acme = tenant('Acme');
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme, null);
    published('parent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'ticket.closed']),
        node('spawn', 'start_workflow', ['definition' => 'child', 'wait' => false]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'spawn', 'received'], ['spawn', 'done', 'started']]), $acme, 'ticket');
    $ticket = ticket($acme);
    $parent = Workflows::start('parent', $ticket, $acme)->dispatch()->instance ?? throw new LogicException;
    pause($acme);

    // An explicit event still reaches the waiting instance; the child it starts is held.
    Workflows::emit('ticket.closed', $ticket);
    $this->travelAndWake('PT1H');

    expect($parent->refresh()->status)->toBe(InstanceStatus::Running)
        ->and($parent->stepRuns()->where('node_id', 'spawn')->where('error_code', 'owner_paused')->count())->toBe(1)
        ->and(Workflows::definition('child', $acme)->model()->instances()->count())->toBe(0);

    pause($acme, false);
    $this->travelAndWake('PT1H');

    expect($parent->refresh())->toHaveCompletedVia(['start', 'wait', 'spawn', 'done'])
        ->and(Workflows::definition('child', $acme)->model()->instances()->count())->toBe(1);
});
