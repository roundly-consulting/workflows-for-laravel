<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * The subject changed: every pending condition wait of its instances gets one re-evaluation
 * job. Nothing is evaluated here — the request that touched the subject may run as another
 * owner; the job restores the instance owner's context first.
 */
final readonly class TouchSubjectAction
{
    public function __construct(
        private EngineQueue $queue,
    ) {}

    public function execute(Model $subject): int
    {
        $waitModel = WorkflowModels::wait();
        $instanceModel = WorkflowModels::instance();

        $waits = $waitModel::query()
            ->where('status', WaitStatus::Pending->value)
            ->where('kind', WaitKind::Condition->value)
            ->whereIn('instance_id', $instanceModel::query()
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', Keys::of($subject))
                ->select('id'))
            ->get();

        $count = 0;

        foreach ($waits as $wait) {
            $this->queue->resume($wait->step_run_id, Keys::of($wait), SignalKind::Condition);
            $count++;
        }

        return $count;
    }
}
