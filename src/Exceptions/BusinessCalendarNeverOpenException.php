<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * A business calendar had no open time within the search window.
 */
final class BusinessCalendarNeverOpenException extends WorkflowsException
{
    public static function within(int $days): self
    {
        return self::make('calendar_never_open', 422, ['days' => $days]);
    }
}
