<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Teams;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Teams\TeamsManager;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Support\Boxed;

/**
 * Runs as the owner team (`Teams::runAs()`, which restores the previous context, also on an
 * exception). A job dispatched from `workflows:wake` carries no team context; this is what makes
 * team scoping work there — and it overrides a hydrated context of another team.
 *
 * @internal
 */
final readonly class TeamsOwnerContext implements RestoresOwnerContext
{
    public function __construct(
        private TeamsManager $teams,
    ) {}

    public function run(?Model $owner, Closure $callback): mixed
    {
        if (! $owner instanceof Team) {
            return $callback();
        }

        return $this->teams->runAs($owner, static fn (): Boxed => new Boxed($callback()))->value;
    }
}
