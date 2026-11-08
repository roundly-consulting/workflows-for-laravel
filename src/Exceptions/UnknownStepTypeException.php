<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * No step type with that key is registered.
 */
final class UnknownStepTypeException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('unknown_step_type', 422, ['key' => $key]);
    }
}
