<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\OwnerState;
use RoundlyConsulting\Workflows\Exceptions\OwnerPausedException;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;

/**
 * The configured OwnerState, asked by every start path and by wake. With none configured nothing
 * is ever paused and wake runs no extra query.
 *
 * @internal
 */
final readonly class Pauses
{
    public function __construct(
        private Container $container,
    ) {}

    public function isPaused(?Model $owner): bool
    {
        return $owner !== null && $this->state()->isPaused($owner);
    }

    /**
     * @throws OwnerPausedException
     */
    public function assertActive(?Model $owner, string $key): void
    {
        if ($this->isPaused($owner)) {
            throw OwnerPausedException::owner($key);
        }
    }

    /**
     * The instances of paused owners among the candidates (queries selecting the instance ids
     * with due work), as a subquery for `whereNotIn('instance_id', …)` — or null when no owner is
     * paused. Each owner is asked once.
     *
     * @param  Builder<covariant Model>  ...$candidates
     * @return Builder<WorkflowInstance>|null
     */
    public function pausedInstances(Builder ...$candidates): ?Builder
    {
        if ($this->state() instanceof ActiveOwnerState) {
            return null;
        }

        $model = WorkflowModels::instance();
        $owners = $model::query()
            ->where(static function (Builder $query) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $query->orWhereIn('id', $candidate);
                }
            })
            ->whereNotNull('owner_type')
            ->distinct()
            ->get(['owner_type', 'owner_id']);

        $paused = [];

        foreach ($owners as $row) {
            $owner = Runtime::load($row->owner_type, $row->owner_id);

            if ($owner !== null && $this->state()->isPaused($owner)) {
                $paused[] = [$row->owner_type, $row->owner_id];
            }
        }

        if ($paused === []) {
            return null;
        }

        return $model::query()->select('id')->where(static function (Builder $query) use ($paused): void {
            foreach ($paused as [$type, $id]) {
                $query->orWhere(static fn (Builder $one): Builder => $one->where('owner_type', $type)->where('owner_id', $id));
            }
        });
    }

    private function state(): OwnerState
    {
        return $this->container->make(OwnerState::class);
    }
}
