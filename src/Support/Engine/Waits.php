<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\WaitSpec;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Persists what a step waits on (wait rows) and when it escalates (the next escalation level).
 *
 * @internal
 */
final class Waits
{
    /**
     * @return list<WorkflowWait>
     */
    public static function create(WorkflowInstance $instance, StepRun $run, WaitSpec $spec, string $timezone, ?string $calendar, ?Model $owner): array
    {
        $now = Clock::now();
        $waits = [];

        if ($spec->until !== null) {
            $waits[] = self::row($instance, $run, WaitKind::Timer, ['due_at' => $spec->until]);
        }

        if ($spec->event !== null) {
            $waits[] = self::row($instance, $run, WaitKind::Event, ['event_key' => $spec->event, 'match' => $spec->match === [] ? ['subject' => true] : $spec->match]);
        }

        if ($spec->condition !== null) {
            $poll = $spec->poll === null ? $now->addMinutes(15) : Durations::add($spec->poll, $now, $timezone, $calendar, $owner);

            $waits[] = self::row($instance, $run, WaitKind::Condition, [
                'condition' => $spec->condition,
                'due_at' => $poll,
                'payload' => ['poll_seconds' => max(60, (int) $now->diffInSeconds($poll, true))],
            ]);
        }

        $timeout = $spec->timeoutAt ?? ($spec->timeout === null ? null : Durations::add($spec->timeout, $now, $timezone, $calendar, $owner));

        if ($timeout !== null) {
            $waits[] = self::row($instance, $run, WaitKind::Timeout, ['due_at' => $timeout, 'outcome' => $spec->timeoutOutcome]);
        }

        foreach ($spec->timers as $timer) {
            $waits[] = self::row($instance, $run, WaitKind::Timer, ['due_at' => $timer]);
        }

        return $waits;
    }

    public static function retry(WorkflowInstance $instance, StepRun $run, CarbonImmutable $due): WorkflowWait
    {
        return self::row($instance, $run, WaitKind::Retry, ['due_at' => $due]);
    }

    /**
     * The first escalation level of a visit, timed from its first attempt's start. Only once per
     * visit, and only on types that support escalations — unless the visit's chain was cut off
     * (its latest level cancelled when the step failed): a manually retried step escalates again,
     * from level 1, timed from the retried attempt.
     */
    public static function escalate(WorkflowInstance $instance, StepRun $run, Node $node, string $timezone, ?string $calendar, ?Model $owner): ?WorkflowEscalation
    {
        if ($node->escalations === []) {
            return null;
        }

        $runModel = WorkflowModels::stepRun();
        $escalationModel = WorkflowModels::escalation();

        $first = $runModel::query()
            ->where('instance_id', Keys::of($instance))
            ->where('node_id', $run->node_id)
            ->where('visit', $run->visit)
            ->where('attempt', 1)
            ->first();

        $first = $first instanceof StepRun ? $first : $run;

        $latest = $escalationModel::query()
            ->whereIn('step_run_id', $runModel::query()->where('instance_id', Keys::of($instance))->where('node_id', $run->node_id)->where('visit', $run->visit)->select('id'))
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return self::level($instance, $first, $node, 1, $first->started_at ?? Clock::now(), $timezone, $calendar, $owner);
        }

        if ($latest->status !== EscalationStatus::Cancelled) {
            return null;
        }

        return self::level($instance, $run, $node, 1, $run->started_at ?? Clock::now(), $timezone, $calendar, $owner);
    }

    public static function level(WorkflowInstance $instance, StepRun $run, Node $node, int $level, CarbonImmutable $from, string $timezone, ?string $calendar, ?Model $owner): ?WorkflowEscalation
    {
        if ($level > WorkflowsConfig::escalationsMaxLevels()) {
            return null;
        }

        $config = $node->escalations[$level - 1] ?? null;

        if (! is_array($config)) {
            return null;
        }

        $after = Durations::parse($config['after'] ?? null);
        $actions = [];

        foreach (is_array($config['actions'] ?? null) ? $config['actions'] : [] as $action) {
            if (is_array($action)) {
                $actions[] = GraphParser::stringKeyed($action);
            }
        }

        if ($after === null) {
            return null;
        }

        $model = WorkflowModels::escalation();
        $escalation = new $model;
        $escalation->instance_id = Keys::of($instance);
        $escalation->step_run_id = Keys::of($run);
        $escalation->level = $level;
        $escalation->due_at = Durations::add($after, $from, $timezone, $calendar, $owner);
        $escalation->actions = $actions;
        $escalation->status = EscalationStatus::Pending;
        $escalation->attempts = 0;
        $escalation->save();

        return $escalation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function row(WorkflowInstance $instance, StepRun $run, WaitKind $kind, array $attributes): WorkflowWait
    {
        $model = WorkflowModels::wait();
        $wait = new $model;
        $wait->instance_id = Keys::of($instance);
        $wait->step_run_id = Keys::of($run);
        $wait->kind = $kind;
        $wait->status = WaitStatus::Pending;

        foreach ($attributes as $key => $value) {
            $wait->setAttribute($key, $value);
        }

        $wait->save();

        return $wait;
    }

    /**
     * The pending (or claimed) waits of a step run.
     *
     * @return list<WorkflowWait>
     */
    public static function pending(StepRun $run): array
    {
        $model = WorkflowModels::wait();

        return array_values($model::query()
            ->where('step_run_id', Keys::of($run))
            ->whereIn('status', [WaitStatus::Pending->value, WaitStatus::Claimed->value])
            ->get()
            ->all());
    }

    /**
     * The signal a fired wait of this kind resumes its step with.
     */
    public static function signalKind(WaitKind $kind): SignalKind
    {
        return match ($kind) {
            WaitKind::Event => SignalKind::Event,
            WaitKind::Condition => SignalKind::Condition,
            WaitKind::Timeout => SignalKind::Timeout,
            default => SignalKind::Timer,
        };
    }

    public static function forGraph(Graph $graph): ?string
    {
        $calendar = $graph->setting('calendar');

        return is_string($calendar) ? $calendar : null;
    }
}
