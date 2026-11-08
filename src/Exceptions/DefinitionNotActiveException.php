<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The definition is a draft or retired: it starts nothing.
 */
final class DefinitionNotActiveException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('definition_not_active', 409, ['key' => $key]);
    }
}
