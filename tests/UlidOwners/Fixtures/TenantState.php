<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\OwnerState;

/**
 * A suspended (read-only) tenant pauses its workflows.
 */
final class TenantState implements OwnerState
{
    public function isPaused(Model $owner): bool
    {
        return $owner instanceof Tenant && $owner->paused;
    }
}
