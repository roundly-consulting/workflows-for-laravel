<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\TransitionOutcome;

/**
 * Requests a lifecycle transition for a `transition` step. The lifecycle decides: a workflow can
 * never bypass its guard. The default adapter is the lifecycle-for-laravel bridge.
 */
interface TransitionAdapter
{
    public function attempt(Model $subject, ?string $lifecycle, string $transition, Actor $actor, string $idempotencyKey, string $reason): TransitionOutcome;

    /**
     * Whether the subject's class has that transition (publish validation); null when unknown.
     *
     * @param  class-string<Model>  $subjectClass
     */
    public function hasTransition(string $subjectClass, ?string $lifecycle, string $transition): ?bool;

    /**
     * The attributes the lifecycle owns on the class (a `set_field` on them is refused).
     *
     * @param  class-string<Model>  $subjectClass
     * @return list<string>
     */
    public function stateAttributes(string $subjectClass): array;
}
