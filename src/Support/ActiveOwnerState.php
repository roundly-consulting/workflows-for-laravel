<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\OwnerState;

/**
 * The owner state when none is configured: no owner is ever paused.
 */
final class ActiveOwnerState implements OwnerState
{
    public function isPaused(Model $owner): bool
    {
        return false;
    }
}
