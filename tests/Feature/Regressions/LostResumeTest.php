<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

// Dual review O-5: an event whose resume job was lost is delivered again; a lost probe is pushed again.

it('re-delivers a claimed event whose resume job was lost', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    Carbon::setTestNow('2026-11-02 09:00:00');
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');

    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $this->runWorkflows();
    expect($instance->refresh()->status)->toBe(InstanceStatus::Waiting);

    expect(Workflows::emit('doc.signed')->resumed)->toBe(1);
    DB::table('jobs')->delete();

    foreach (['2026-11-02 09:30:00', '2026-11-02 10:30:00'] as $at) {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        Workflows::wake();
        $this->runWorkflows();
    }

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($instance->path())->toBe(['start', 'wait', 'done']);
});

it('probes a waiting step again when its probe job was lost', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    Carbon::setTestNow('2026-11-02 09:00:00');
    CarbonImmutable::setTestNow('2026-11-02 09:00:00');

    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'never']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    published('parent', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('sw', 'start_workflow', ['definition' => 'child', 'wait' => true]),
        node('done', 'end'),
    ], [['start', 'sw'], ['sw', 'done', 'completed'], ['sw', 'done', 'failed'], ['sw', 'done', 'cancelled']]), null, null);

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $sw = StepRun::query()->where('instance_id', $parent->id)->where('node_id', 'sw')->sole();
    app(RunStepAction::class)->execute($sw->id);

    // The probe job is lost, and the child ended before the parent's wait could hear it.
    DB::table('jobs')->delete();
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->sole();
    $child->update(['status' => InstanceStatus::Completed->value]);
    StepRun::query()->where('instance_id', $child->id)->where('status', 'queued')->update(['status' => 'cancelled']);

    Carbon::setTestNow('2026-11-02 09:02:00');
    CarbonImmutable::setTestNow('2026-11-02 09:02:00');
    expect(Workflows::wake()->requeued)->toBe(0);

    Carbon::setTestNow('2026-11-02 09:10:00');
    CarbonImmutable::setTestNow('2026-11-02 09:10:00');
    expect(Workflows::wake()->requeued)->toBe(1);
    $this->runWorkflows();

    expect($parent->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($sw->refresh()->outcome)->toBe('completed')
        ->and(Workflows::wake()->requeued)->toBe(0);
});
