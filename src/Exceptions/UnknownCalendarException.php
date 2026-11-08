<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\Support\Suggestions;

/**
 * No business calendar is registered under that name (or the owner has none); the message
 * suggests the closest registered name.
 */
final class UnknownCalendarException extends WorkflowsException
{
    /**
     * @param  iterable<string>  $known  the registered names
     */
    public static function name(string $name, iterable $known = []): self
    {
        $suggestion = Suggestions::closest($name, $known);

        return $suggestion === null
            ? self::make('unknown_calendar', 422, ['calendar' => $name])
            : self::make('unknown_calendar', 422, ['calendar' => $name, 'suggestion' => $suggestion], 'unknown_calendar_suggest');
    }
}
