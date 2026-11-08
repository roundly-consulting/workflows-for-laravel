<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Teams;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Teams\Support\TeamModel;
use RoundlyConsulting\Teams\Traits\BelongsToTeam;
use RoundlyConsulting\Teams\Traits\ScopedToTeam;
use RoundlyConsulting\Workflows\Support\AllowMembership;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\NullOwnerContext;
use RoundlyConsulting\Workflows\Support\Owners;

/**
 * teams-for-laravel, when installed: team owners run every queued step inside `Teams::runAs()`,
 * team roles resolve `role` parties (active memberships only), membership is an active one, and
 * a `BelongsToTeam` / `ScopedToTeam` subject is owned by its team.
 *
 * @internal
 */
final class TeamsBridge
{
    public static function owners(Owners $owners): void
    {
        $owners->resolveUsing(static fn (Model $subject): Model|false|null => self::ownerOf($subject));
    }

    /**
     * @return class-string
     */
    public static function defaultContext(): string
    {
        return Bridges::available('teams') ? TeamsOwnerContext::class : NullOwnerContext::class;
    }

    /**
     * @return class-string
     */
    public static function defaultMembership(): string
    {
        return Bridges::available('teams') ? TeamMembership::class : AllowMembership::class;
    }

    public static function defaultRoles(): ?string
    {
        return Bridges::available('teams') ? TeamRoles::class : null;
    }

    /**
     * The team of a team-owned subject; false for a subject that is not team-owned.
     */
    public static function ownerOf(Model $subject): Model|false|null
    {
        $traits = class_uses_recursive($subject);

        if (! in_array(ScopedToTeam::class, $traits, true) && ! in_array(BelongsToTeam::class, $traits, true)) {
            return false;
        }

        $column = method_exists($subject, 'getTeamColumn') ? $subject->getTeamColumn() : 'team_id';
        $id = $subject->getAttribute(is_string($column) ? $column : 'team_id');

        if ($id === null) {
            return null;
        }

        $team = TeamModel::query()->find($id);

        return $team instanceof Team ? $team : null;
    }
}
