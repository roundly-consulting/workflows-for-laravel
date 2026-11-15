<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether an owner's workflows are paused (a suspended or read-only company). Configured at
 * `workflows.owners.state`; none = never paused. While an owner is paused, `workflows:wake` fires
 * none of its due timers, retries, timeouts, condition polls and escalations (they stay pending
 * and fire once on the first wake after the pause ends), its schedule slots are skipped (never
 * replayed), and every start for it — manual, event, webhook, schedule, child, retry — throws
 * OwnerPausedException. Asked once per owner per wake pass and once per start, so keep it cheap.
 */
interface OwnerState
{
    public function isPaused(Model $owner): bool;
}
