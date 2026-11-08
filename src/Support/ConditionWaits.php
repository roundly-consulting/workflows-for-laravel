<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ReflectionMethod;
use ReflectionNamedType;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;

/**
 * Whether an update of a subject needs a `touch()`: only when it changed something a condition
 * reads, and the subject has a pending condition wait (one indexed exists query).
 *
 * @internal
 */
final class ConditionWaits
{
    /**
     * Whether an update can have changed what a condition wait reads: a registered field's
     * attribute (or a belongs-to field's foreign key) changed. A field read through another
     * relation is unknowable, so it always counts; an unregistered model always counts.
     */
    public static function affectedBy(Model $subject): bool
    {
        $type = app(SubjectRegistry::class)->forModel($subject);

        if ($type === null) {
            return true;
        }

        $changed = array_keys($subject->getChanges());

        foreach ($type->fields as $field) {
            $path = $field->path();

            if ($field->isRelationPath()) {
                return true;
            }

            if (in_array($path, $changed, true)) {
                return true;
            }

            if (method_exists($subject, $path) && self::changedRelation($subject, $path, $changed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A relation field counts as changed when its declared belongs-to foreign key changed; a method
     * not declared to return BelongsTo is never called, and always counts.
     *
     * @param  list<string>  $changed
     */
    private static function changedRelation(Model $subject, string $method, array $changed): bool
    {
        $type = (new ReflectionMethod($subject, $method))->getReturnType();

        if (! $type instanceof ReflectionNamedType || ! is_a($type->getName(), BelongsTo::class, true)) {
            return true;
        }

        $relation = $subject->{$method}();

        return ! $relation instanceof BelongsTo || in_array($relation->getForeignKeyName(), $changed, true);
    }

    public static function exist(Model $subject): bool
    {
        $waitModel = WorkflowModels::wait();
        $instanceModel = WorkflowModels::instance();

        return $waitModel::query()
            ->where('status', WaitStatus::Pending->value)
            ->where('kind', WaitKind::Condition->value)
            ->whereIn('instance_id', $instanceModel::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->select('id'))
            ->exists();
    }
}
