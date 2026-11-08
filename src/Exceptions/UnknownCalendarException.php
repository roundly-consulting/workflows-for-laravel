<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * No business calendar is registered under that name (or the owner has none).
 */
final class UnknownCalendarException extends WorkflowsException
{
    public static function name(string $name): self
    {
        return self::make('unknown_calendar', 422, ['calendar' => $name]);
    }
}
