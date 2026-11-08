<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;

/**
 * Whether a subject has any pending condition wait (one indexed exists query): what decides if
 * an update of the subject needs a `touch()`.
 *
 * @internal
 */
final class ConditionWaits
{
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
