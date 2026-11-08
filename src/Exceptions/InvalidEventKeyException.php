<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * An emit with an internal signal key (`@…`) or a key outside the dotted event-key pattern.
 */
final class InvalidEventKeyException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('invalid_event_key', 422, ['key' => $key]);
    }
}
