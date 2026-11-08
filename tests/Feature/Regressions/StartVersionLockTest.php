<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Workflows\Exceptions\DefinitionNotActiveException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

// Dual review F-6: a start uses the definition as it stands once it holds the definition lock.

/**
 * Run $change once, at the first transaction that opens after this call.
 */
function onNextTransaction(Closure $change): void
{
    $armed = true;
    Event::listen(TransactionBeginning::class, function () use (&$armed, $change): void {
        if (! $armed) {
            return;
        }

        $armed = false;
        $change();
    });
}

beforeEach(function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('a', 'acme.spy'), node('done', 'end')], [['start', 'a'], ['a', 'done']]), null, null);
});

it('starts on the version published while it waited for the lock', function (): void {
    onNextTransaction(function (): void {
        Workflows::definition('flow')->edit(graph([node('start', 'trigger', ['trigger' => 'manual']), node('b', 'acme.spy'), node('done', 'end')], [['start', 'b'], ['b', 'done']]));
        Workflows::definition('flow')->publish();
    });

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect($instance->refresh()->version?->number)->toBe(2)
        ->and($instance->path())->toBe(['start', 'b', 'done']);
});

it('refuses a start on a definition retired while it waited for the lock', function (): void {
    onNextTransaction(fn () => Workflows::definition('flow')->retire());

    expect(fn () => Workflows::start('flow')->dispatch())->toThrow(DefinitionNotActiveException::class)
        ->and(WorkflowInstance::query()->count())->toBe(0);
});
