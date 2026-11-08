<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * A definition key outside `^[a-z0-9][a-z0-9._-]{0,99}$`, or a start without the subject the
 * definition's subject type requires.
 */
final class InvalidDefinitionException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('invalid_definition_key', 422, ['key' => $key]);
    }

    public static function subjectless(string $key): self
    {
        return self::make('definition_needs_subject', 422, ['key' => $key]);
    }
}
