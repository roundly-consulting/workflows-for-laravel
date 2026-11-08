<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The definition's trigger is not started this way (an event trigger starts through emit()).
 */
final class TriggerMismatchException extends WorkflowsException
{
    public static function expected(string $key, string $trigger, string $path): self
    {
        return self::make('trigger_mismatch', 409, ['key' => $key, 'trigger' => $trigger, 'path' => $path]);
    }
}
