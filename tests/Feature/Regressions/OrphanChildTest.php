<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;

// Dual review F-9: a child started by an attempt that was running at cancel time is cancelled too.

beforeEach(function (): void {
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'never']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    published('parent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('sw', 'start_workflow', ['definition' => 'child', 'wait' => true]),
        node('done', 'end'),
    ], [['start', 'sw'], ['sw', 'done', 'completed'], ['sw', 'done', 'failed'], ['sw', 'done', 'cancelled']]), null, null);
});

/**
 * Turn the parent's waiting `sw` attempt into a failed one with a queued retry (no output yet).
 */
function queuedRetryOf(WorkflowInstance $parent): StepRun
{
    $first = StepRun::query()->where('instance_id', $parent->id)->where('node_id', 'sw')->sole();
    $first->status = StepRunStatus::Failed;
    $first->save();

    $retry = $first->replicate(['claim_token']);
    $retry->attempt = 2;
    $retry->status = StepRunStatus::Queued;
    $retry->output = null;
    $retry->save();

    return $first;
}

it('cancels a child started by an attempt that was running at cancel time', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    Bus::fake();

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('node_id', 'sw')->sole();
    $claim = (fn (string|int $instance, string|int $run) => $this->claim($instance, $run))->call(app(RunStepAction::class), $parent->id, $run->id);

    // The cancel lands between the claim and the handler's child start.
    Workflows::instances()->cancel($parent);
    $outcome = app(Attempts::class)->run($claim->instance, $claim->run);
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->sole();
    expect($child->status)->toBe(InstanceStatus::Running);

    app(RecordStepResultAction::class)->execute($parent->id, $run->id, $claim->token, $outcome->result, $outcome->transactional);

    expect($parent->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and($child->refresh()->status)->toBe(InstanceStatus::Cancelled);
});

it('cancels the child of a step queued for a retry', function (): void {
    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->sole();
    queuedRetryOf($parent);

    Workflows::instances()->cancel($parent);

    expect($child->refresh()->status)->toBe(InstanceStatus::Cancelled);
});

it('leaves an ended child alone', function (): void {
    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->sole();
    $child->update(['status' => InstanceStatus::Completed->value]);

    Workflows::instances()->cancel($parent);

    expect($parent->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and($child->refresh()->status)->toBe(InstanceStatus::Completed);
});

it('never cancels an instance that is not the step\'s own child', function (): void {
    $stranger = Workflows::start('child')->dispatch()->instance ?? throw new LogicException;
    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $first = queuedRetryOf($parent);
    $first->output = ['instance' => $stranger->ulid];
    $first->save();

    Workflows::instances()->cancel($parent);

    expect($stranger->refresh()->status)->toBe(InstanceStatus::Waiting);
});
