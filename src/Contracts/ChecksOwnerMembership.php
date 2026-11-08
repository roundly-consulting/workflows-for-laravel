<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether a user belongs to an owner (teams: an active membership). Parties that name a user
 * directly or through a field are dropped when they are not members.
 */
interface ChecksOwnerMembership
{
    public function isMember(?Model $owner, Model $user): bool;
}
