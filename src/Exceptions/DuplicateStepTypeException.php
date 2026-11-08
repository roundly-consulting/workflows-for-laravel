<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * Two different classes registered the same step type key.
 */
final class DuplicateStepTypeException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('duplicate_step_type', 500, ['key' => $key]);
    }
}
