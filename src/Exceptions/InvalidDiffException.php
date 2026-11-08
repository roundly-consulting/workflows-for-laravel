<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * Versions of different definitions cannot be compared.
 */
final class InvalidDiffException extends WorkflowsException
{
    public static function differentDefinitions(): self
    {
        return self::make('invalid_diff', 422);
    }
}
