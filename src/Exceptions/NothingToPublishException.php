<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * Publish was called on a definition without a draft.
 */
final class NothingToPublishException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('nothing_to_publish', 409, ['key' => $key]);
    }
}
