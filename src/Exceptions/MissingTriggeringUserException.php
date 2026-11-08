<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * An on_behalf_of definition was started without a triggering user.
 */
final class MissingTriggeringUserException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('missing_triggering_user', 422, ['key' => $key]);
    }
}
