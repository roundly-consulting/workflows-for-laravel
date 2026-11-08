<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The key is taken for this owner, also by a soft-deleted definition (restore it instead).
 */
final class DefinitionKeyTakenException extends WorkflowsException
{
    public static function key(string $key, bool $trashed = false): self
    {
        return self::make($trashed ? 'definition_key_trashed' : 'definition_key_taken', 409, ['key' => $key]);
    }
}
