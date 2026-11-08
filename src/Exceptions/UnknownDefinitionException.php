<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * No definition with that key exists for the owner (another owner's key looks missing).
 */
final class UnknownDefinitionException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('unknown_definition', 404, ['key' => $key]);
    }
}
