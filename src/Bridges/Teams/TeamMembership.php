<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Teams;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Contracts\ChecksOwnerMembership;

/**
 * A user belongs to a team owner when the membership is active (expired ones do not count).
 *
 * @internal
 */
final class TeamMembership implements ChecksOwnerMembership
{
    public function isMember(?Model $owner, Model $user): bool
    {
        return ! $owner instanceof Team || $owner->isAccessibleBy($user);
    }
}
