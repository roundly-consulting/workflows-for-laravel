<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\ResumeStepAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Events\InstanceResumed;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Jobs\ResumeStepJob;
use RoundlyConsulting\Workflows\Jobs\RunStepJob;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;

// Dual review O-1 / F-1: a signal landing while the step's probe runs must not be lost.

/**
 * A parent that starts `child` and waits for it.
 */
function probeRaceDefinitions(): void
{
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy'), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);
    published('parent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('sw', 'start_workflow', ['definition' => 'child', 'wait' => true]),
        node('done', 'end'),
    ], [['start', 'sw'], ['sw', 'done', 'completed'], ['sw', 'done', 'failed'], ['sw', 'done', 'cancelled']]), null, null);
}

it('resumes a parent whose child ended while its probe was between read and record', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    probeRaceDefinitions();

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $sw = $parent->stepRuns()->where('node_id', 'sw')->first() ?? throw new LogicException;
    app(RunStepAction::class)->execute($sw->id);

    expect($sw->refresh()->status)->toBe(StepRunStatus::Waiting);

    // Worker A claims the probe and reads the child: still running.
    $resume = app(ResumeStepAction::class);
    $claim = (fn (string $instance, string $run) => $this->claim($instance, $run, null, SignalKind::Probe))->call($resume, $parent->id, $sw->id);
    $outcome = app(Attempts::class)->run($claim->instance, $claim->run, new Signal(SignalKind::Probe));

    // Worker B runs the child to its end: the child signals the parent.
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->firstOrFail();
    while ($next = StepRun::query()->where('instance_id', $child->id)->where('status', StepRunStatus::Queued->value)->first()) {
        app(RunStepAction::class)->execute($next->id);
    }
    expect($child->refresh()->status)->toBe(InstanceStatus::Completed);

    // Worker C runs the resume the child's signal queued, while the parent run is Running.
    $wait = WorkflowWait::query()->where('step_run_id', $sw->id)->where('kind', 'event')->firstOrFail();
    app(ResumeStepAction::class)->execute($sw->id, $wait->id, SignalKind::Event);

    // Worker A records its stale "still waiting". Every job queued so far has been run by hand.
    DB::table('jobs')->delete();
    app(RecordStepResultAction::class)->execute($parent->id, $sw->id, $claim->token, $outcome->result, $outcome->transactional, new Signal(SignalKind::Probe));
    $this->runWorkflows();

    expect($parent->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(WorkflowWait::query()->where('instance_id', $parent->id)->where('status', WaitStatus::Pending->value)->count())->toBe(0);
});

it('resumes a parent whose child ended inside the probe record window', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    Bus::fake();
    probeRaceDefinitions();

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $kick = Bus::dispatched(RunStepJob::class)->first() ?? throw new LogicException;
    app(RunStepAction::class)->execute($kick->stepRunId);

    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->firstOrFail();
    $probe = Bus::dispatched(ResumeStepJob::class)->first() ?? throw new LogicException;
    $childJobs = Bus::dispatched(RunStepJob::class)->filter(fn (RunStepJob $job): bool => $job->stepRunId !== $kick->stepRunId);
    expect($probe->kind)->toBe(SignalKind::Probe->value);

    // The child ends (and its signal's resume runs) between the probe's read and its record.
    $armed = false;
    $mark = 0;
    Event::listen(InstanceResumed::class, function () use (&$armed): void {
        $armed = true;
    });
    Event::listen(TransactionBeginning::class, function () use (&$armed, &$mark, $childJobs, $child): void {
        if (! $armed) {
            return;
        }

        $armed = false;
        foreach ($childJobs as $job) {
            app(RunStepAction::class)->execute($job->stepRunId);
        }
        while ($next = StepRun::query()->where('instance_id', $child->id)->where('status', StepRunStatus::Queued->value)->first()) {
            app(RunStepAction::class)->execute($next->id);
        }

        $resume = Bus::dispatched(ResumeStepJob::class)->first(fn (ResumeStepJob $job): bool => $job->kind === SignalKind::Event->value) ?? throw new LogicException;
        app(ResumeStepAction::class)->execute($resume->stepRunId, $resume->waitId, SignalKind::Event);
        $mark = Bus::dispatched(ResumeStepJob::class)->count();
    });

    app(ResumeStepAction::class)->execute($probe->stepRunId, $probe->waitId, SignalKind::Probe);
    expect($child->refresh()->status)->toBe(InstanceStatus::Completed);

    // Whatever the record queued runs now: the parent must end, not wait on a spent key.
    Event::forget(TransactionBeginning::class);
    expect($mark)->toBeGreaterThan(0);

    foreach (Bus::dispatched(ResumeStepJob::class)->slice($mark) as $job) {
        if ($parent->refresh()->status === InstanceStatus::Waiting) {
            app(ResumeStepAction::class)->execute($job->stepRunId, $job->waitId, SignalKind::from($job->kind));
        }
    }

    expect($parent->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(WorkflowWait::query()->where('instance_id', $parent->id)->where('status', WaitStatus::Pending->value)->count())->toBe(0);
});
