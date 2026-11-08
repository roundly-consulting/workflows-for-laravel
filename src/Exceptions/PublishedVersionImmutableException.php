<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * A published version's frozen column was about to change.
 */
final class PublishedVersionImmutableException extends WorkflowsException
{
    public static function column(string $column): self
    {
        return self::make('published_version_immutable', 409, ['column' => $column]);
    }
}
