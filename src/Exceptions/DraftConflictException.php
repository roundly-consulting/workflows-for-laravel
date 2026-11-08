<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The draft changed since the caller read it (two builder tabs).
 */
final class DraftConflictException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('draft_conflict', 409, ['key' => $key]);
    }
}
