<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Teams;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Teams\Support\MemberModel;
use RoundlyConsulting\Teams\Support\TeamsConfig;
use RoundlyConsulting\Workflows\Contracts\ResolvesRoles;

/**
 * `role` parties on a team owner: the members holding that role through an active membership
 * (with `teams.nesting.inherit_roles_down` on, also through a team above it).
 *
 * @internal
 */
final class TeamRoles implements ResolvesRoles
{
    public function members(?Model $owner, string $role): array
    {
        if (! $owner instanceof Team) {
            return [];
        }

        $teams = [$owner->getKey()];

        if (TeamsConfig::inheritRolesDown()) {
            foreach ($owner->ancestors() as $ancestor) {
                $teams[] = $ancestor->getKey();
            }
        }

        $members = [];

        foreach (MemberModel::query()->active()->whereIn('team_id', $teams)->where('role', $role)->with('member')->orderBy('id')->get() as $membership) {
            if ($membership->member instanceof Model) {
                $members[] = $membership->member;
            }
        }

        return $members;
    }
}
