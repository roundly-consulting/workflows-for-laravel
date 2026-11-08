<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Events\Dispatcher;

/**
 * Dispatches a package event through the contract (no Foundation helpers). Events implement
 * ShouldDispatchAfterCommit, so one raised inside a transaction waits for its commit.
 *
 * @internal
 */
final class Events
{
    public static function dispatch(object $event): void
    {
        app(Dispatcher::class)->dispatch($event);
    }
}
