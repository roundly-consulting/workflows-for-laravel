<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Support\Racer;

/**
 * Two sessions at once on a real engine (pgsql, mysql): the parent holds its transaction open
 * while a forked racer runs the competing call, then commits; the racer must wait on the lock and
 * decide on the committed state.
 */
function racingFlow(): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'go.on']),
        node('spy', 'acme.spy'),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'spy', 'received'], ['spy', 'done']]);
}

// C-59
it('returns the same instance to two parallel starts with one idempotency key', function (): void {
    published('flow', racingFlow(), null, null);
    $racer = new Racer;

    $parent = DB::transaction(function () use ($racer): WorkflowInstance {
        $result = Workflows::start('flow')->idempotencyKey('order-1')->dispatch();

        $racer->run(static function (): string {
            $result = Workflows::start('flow')->idempotencyKey('order-1')->dispatch();

            return ($result->created ? 'created ' : 'existing ').$result->instance?->ulid;
        })->waitUntilBlockedOrDone();

        return $result->instance ?? throw new LogicException;
    });

    expect($racer->outcome())->toBe('existing '.$parent->ulid)
        ->and(WorkflowInstance::query()->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// C-44
it('never advances one instance twice when two signals race', function (): void {
    published('flow', racingFlow(), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $racer = new Racer;

    DB::transaction(function () use ($racer): void {
        Workflows::emit('go.on');

        $racer->run(static fn (): string => (string) Workflows::emit('go.on')->resumed)->waitUntilBlockedOrDone();
    });

    expect($racer->outcome())->toBe('0')
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($instance->stepRuns()->where('node_id', 'spy')->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

// C-36
it('keeps one draft per definition when two editors race', function (): void {
    published('flow', racingFlow(), null, null);
    $racer = new Racer;

    DB::transaction(function () use ($racer): void {
        Workflows::definition('flow')->edit(racingFlow());

        $racer->run(static fn (): string => (string) Workflows::definition('flow')->edit(racingFlow())?->number)->waitUntilBlockedOrDone();
    });

    // The published v1 plus exactly one draft, which both editors wrote.
    expect($racer->outcome())->toBe('2')
        ->and(Workflows::definition('flow')->versions())->toHaveCount(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
