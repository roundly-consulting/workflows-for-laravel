<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The owner's workflows are paused (`Contracts\OwnerState`): nothing starts for it until the
 * pause ends. 423 Locked; nothing was recorded, so the same start (its idempotency key included)
 * can be sent again once the owner is active.
 */
final class OwnerPausedException extends WorkflowsException
{
    public static function owner(string $key): self
    {
        return self::make('owner_paused', 423, ['key' => $key]);
    }
}
