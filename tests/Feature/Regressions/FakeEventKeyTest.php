<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Exceptions\InvalidEventKeyException;
use RoundlyConsulting\Workflows\Facades\Workflows;

// Chat review C-6: the fake refuses the event keys the real emit refuses, through the same check.

it('refuses an event key under the fake that the real emit refuses', function (string $key): void {
    $fake = Workflows::fake();

    expect(fn () => Workflows::emit($key))->toThrow(InvalidEventKeyException::class)
        ->and(fn () => Workflows::for(document())->emit($key))->toThrow(InvalidEventKeyException::class);

    $fake->assertNothingEmitted();
})->with(['Order Placed', '@x', '@approvals.request.1', '']);

it('records a valid event key under the fake', function (): void {
    $fake = Workflows::fake();

    Workflows::emit('order.placed');

    $fake->assertEmitted('order.placed');
});
