<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * A webhook start without the idempotency key its trigger requires.
 */
final class MissingIdempotencyKeyException extends WorkflowsException
{
    public static function webhook(): self
    {
        return self::make('missing_idempotency_key', 422);
    }
}
