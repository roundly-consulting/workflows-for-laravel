<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

// Chat review C-3: a child the start-rate limit refused was never started, so the step (or the
// escalation) that asked for it does not count it as started: it retries with backoff instead.

function refusedChildClock(string $at): void
{
    Carbon::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
}

/**
 * The children of `helper` the given parent started (the window filler has no parent).
 */
function helperChildrenOf(WorkflowInstance $parent): array
{
    return WorkflowInstance::query()->where('parent_instance_id', $parent->id)->orderBy('id')->pluck('status')->map(static fn (InstanceStatus $status): string => $status->value)->all();
}

beforeEach(function (): void {
    refusedChildClock('2026-11-02 09:00:00');
    config()->set('workflows.limits.max_starts_per_minute', 1);
    config()->set('workflows.retry.default', ['max_attempts' => 3, 'backoff' => [60], 'jitter' => 0]);
    published('helper', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
});

it('retries a start_workflow step whose child was refused, then starts it', function (bool $wait): void {
    $nodes = [
        node('start', 'trigger', ['trigger' => 'manual']),
        node('child', 'start_workflow', ['definition' => 'helper', 'wait' => $wait]),
        node('done', 'end'),
    ];
    $edges = [['start', 'child'], ['child', 'done', 'started']];

    if ($wait) {
        $nodes[] = node('failed', 'end', ['status' => 'failed']);
        $edges = [['start', 'child'], ['child', 'done', 'completed'], ['child', 'failed', 'failed']];
    }

    published('parent', graph($nodes, $edges), null, null);

    // The helper's window is full.
    Workflows::start('helper')->dispatch();
    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $first = StepRun::query()->where('instance_id', $parent->id)->where('node_id', 'child')->orderBy('attempt')->firstOrFail();

    expect($parent->refresh()->status)->toBe(InstanceStatus::Running)
        ->and($first->outcome)->toBeNull()
        ->and($first->error_code)->toBe('child_not_started')
        ->and($first->error_retryable)->toBeTrue()
        ->and(helperChildrenOf($parent))->not->toContain('running', 'completed');

    $this->travelAndWake('PT2M');

    $runs = StepRun::query()->where('instance_id', $parent->id)->where('node_id', 'child')->orderBy('attempt')->get();

    expect($parent->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($runs->pluck('outcome')->all())->toBe([null, $wait ? 'completed' : 'started'])
        ->and(helperChildrenOf($parent))->toContain('completed');
})->with(['wait: false' => false, 'wait: true' => true]);

it('retries an escalation whose start_workflow child was refused, then starts it', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed', 'timeout' => 'P2D'], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'start_workflow', 'definition' => 'helper']]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received'], ['wait', 'done', 'timed_out']]), null, null);
    $parent = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    refusedChildClock('2026-11-02 10:00:00');
    Workflows::start('helper')->dispatch();
    Workflows::wake();

    $escalation = WorkflowEscalation::query()->sole();

    expect($escalation->status)->toBe(EscalationStatus::Pending)
        ->and($escalation->attempts)->toBe(1)
        ->and($escalation->error_code)->toBe('child_not_started')
        ->and($escalation->due_at?->toDateTimeString())->toBe('2026-11-02 10:01:00')
        ->and(helperChildrenOf($parent))->toBe([]);

    $this->travelAndWake('PT2M');

    expect($escalation->refresh()->status)->toBe(EscalationStatus::Fired)
        ->and(helperChildrenOf($parent))->toBe(['completed'])
        ->and($parent->refresh()->status)->toBe(InstanceStatus::Waiting);
});
