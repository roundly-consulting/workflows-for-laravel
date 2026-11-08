<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamsBridge;
use RoundlyConsulting\Workflows\Contracts\HasWorkflowOwner;

/**
 * Who owns a subject, in order: `HasWorkflowOwner`, the subject type's `ownedBy()`, a bridge
 * resolver (teams: the team column), else unknown. An owned definition matches a subject only
 * when the owner is the same morph class and key; unknown is a mismatch (fail closed).
 *
 * @internal
 */
final class Owners
{
    /** @var list<Closure(Model): (Model|false|null)> */
    private array $resolvers = [];

    private bool $loaded = false;

    public function __construct(
        private readonly SubjectRegistry $subjects,
    ) {}

    /**
     * @param  Closure(Model): (Model|false|null)  $resolver  null = no owner, false = not mine to say
     */
    public function resolveUsing(Closure $resolver): void
    {
        $this->load();
        $this->resolvers[] = $resolver;
    }

    /**
     * Whether the subject's owner can be resolved at all (an owner, or an explicit "none").
     *
     * @param  class-string<Model>  $class
     */
    public function resolvable(string $class): bool
    {
        $this->load();

        if (is_subclass_of($class, HasWorkflowOwner::class)) {
            return true;
        }

        if ($this->subjects->forModel($class)?->ownedBy !== null) {
            return true;
        }

        $probe = new $class;

        foreach ($this->resolvers as $resolver) {
            if ($resolver($probe) !== false) {
                return true;
            }
        }

        return false;
    }

    public function ownerOf(Model $subject): ?Model
    {
        $this->load();

        if ($subject instanceof HasWorkflowOwner) {
            return $subject->workflowOwner();
        }

        $ownedBy = $this->subjects->forModel($subject)?->ownedBy;

        if ($ownedBy !== null) {
            $owner = method_exists($subject, $ownedBy) && $subject->{$ownedBy}() instanceof Relation
                ? $subject->getRelationValue($ownedBy)
                : $subject->getAttribute($ownedBy);

            return $owner instanceof Model ? $owner : null;
        }

        foreach ($this->resolvers as $resolver) {
            $owner = $resolver($subject);

            if ($owner !== false) {
                return $owner;
            }
        }

        return null;
    }

    public function matches(?Model $owner, ?Model $subject): bool
    {
        if ($owner === null || $subject === null) {
            return true;
        }

        return self::same($owner, $this->ownerOf($subject));
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (Bridges::available('teams')) {
            TeamsBridge::owners($this);
        }
    }

    public static function same(?Model $a, ?Model $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->getMorphClass() === $b->getMorphClass() && (string) Keys::of($a) === (string) Keys::of($b);
    }

    public static function scope(?Model $owner): string
    {
        return $owner === null ? '*' : $owner->getMorphClass().':'.Keys::of($owner);
    }
}
