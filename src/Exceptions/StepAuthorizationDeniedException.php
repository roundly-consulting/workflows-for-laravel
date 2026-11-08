<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The authorization hook denied a side-effect step (or a handler's own authorize() call).
 */
final class StepAuthorizationDeniedException extends WorkflowsException
{
    public static function because(?string $reason, string $ability): self
    {
        return $reason !== null && $reason !== ''
            ? new self($reason, 'authorization_denied', 403)
            : self::make('authorization_denied', 403, ['ability' => $ability]);
    }
}
