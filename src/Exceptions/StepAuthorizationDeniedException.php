<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The authorization hook denied a side-effect step (or a handler's own authorize() call).
 */
final class StepAuthorizationDeniedException extends WorkflowsException
{
    public static function because(?string $reason, ?string $ability, string $node = ''): self
    {
        return match (true) {
            $reason !== null && $reason !== '' => new self($reason, 'authorization_denied', 403),
            $ability !== null => self::make('authorization_denied', 403, ['ability' => $ability]),
            default => self::make('authorization_denied', 403, ['node' => $node], 'authorization_denied_step'),
        };
    }
}
