<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\ChecksOwnerMembership;

/**
 * The membership check for apps without tenancy: everyone belongs (documented; bind your own).
 */
final class AllowMembership implements ChecksOwnerMembership
{
    public function isMember(?Model $owner, Model $user): bool
    {
        return true;
    }
}
