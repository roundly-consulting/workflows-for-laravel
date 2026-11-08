<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Workflows\Actions\Engine\FireEscalationAction;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Events\EscalationFailed;
use RoundlyConsulting\Workflows\Events\EscalationTriggered;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\EscalationActions;

function escalating(array $levels): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => $levels]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]);
}

function now_(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function chaser(): void
{
    published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy'), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);
}

// C-67: level n + 1 is timed from level n firing.
it('times the next level from the previous one firing', function (): void {
    Event::fake([EscalationTriggered::class]);
    now_('2026-11-06 10:00');
    chaser();
    published('flow', escalating([
        ['after' => ['amount' => 2, 'unit' => 'business_days'], 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]],
        ['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]],
    ]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $first = WorkflowEscalation::query()->first();

    expect($first?->due_at->format('D H:i'))->toBe('Tue 10:00');

    $this->travelAndWake(CarbonImmutable::parse('2026-11-09 10:00', 'UTC'));
    expect(Workflows::wake()->escalations)->toBe(0);

    $this->travelAndWake(CarbonImmutable::parse('2026-11-10 10:05', 'UTC'));
    $second = WorkflowEscalation::query()->where('level', 2)->first();

    expect($first?->refresh()->status)->toBe(EscalationStatus::Fired)
        ->and($second?->due_at->format('D H:i'))->toBe('Wed 10:05')
        ->and(WorkflowInstance::query()->where('parent_instance_id', $instance->id)->count())->toBe(1);

    Event::assertDispatched(EscalationTriggered::class, fn (EscalationTriggered $event): bool => $event->level === 1 && $event->actor->is($instance));

    $this->travelAndWake(CarbonImmutable::parse('2026-11-11 10:05', 'UTC'));

    expect(WorkflowInstance::query()->where('parent_instance_id', $instance->id)->count())->toBe(2)
        ->and(WorkflowEscalation::query()->count())->toBe(2)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Waiting);
});

// C-70: escalations are cancelled when the step finishes.
it('cancels pending escalations when the step finishes', function (): void {
    now_('2026-11-06 10:00');
    chaser();
    published('flow', escalating([['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::emit('doc.signed');

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(WorkflowEscalation::query()->first()?->status)->toBe(EscalationStatus::Cancelled);
});

// C-68: an escalation that comes due as the step completes is cancelled, never fired.
it('cancels an escalation whose step finished before it fired', function (): void {
    now_('2026-11-06 10:00');
    chaser();
    published('flow', escalating([['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $escalation = WorkflowEscalation::query()->first() ?? throw new LogicException;
    // Claimed by a wake, but the step completes before its job runs.
    $escalation->update(['status' => EscalationStatus::Claimed]);
    Workflows::emit('doc.signed');
    app(FireEscalationAction::class)->execute($escalation->id);

    expect($escalation->refresh()->status)->toBe(EscalationStatus::Cancelled)
        ->and(WorkflowInstance::query()->where('parent_instance_id', $instance->id)->count())->toBe(0);
});

// C-69: a failing escalation never fails the step.
it('retries a failing escalation action, then fails the escalation, never the step', function (): void {
    Event::fake([EscalationFailed::class]);
    now_('2026-11-06 10:00');
    config()->set('workflows.retry.default', ['max_attempts' => 2, 'backoff' => [60], 'jitter' => 0]);
    $calls = 0;
    app(EscalationActions::class)->register(
        'page',
        static function () use (&$calls): void {
            $calls++;

            throw new RuntimeException('Pager down.');
        },
        static fn (): array => [],
    );
    published('flow', escalating([['after' => 'P1D', 'actions' => [['type' => 'page']]]]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    $this->travelAndWake('P1D');
    $escalation = WorkflowEscalation::query()->first();

    expect($escalation?->status)->toBe(EscalationStatus::Pending)->and($escalation?->attempts)->toBe(1);

    $this->travelAndWake('PT2M');

    expect($escalation?->refresh()->status)->toBe(EscalationStatus::Failed)
        ->and($escalation?->error_code)->toBe('exception')
        ->and($calls)->toBe(2)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Waiting);

    Event::assertDispatched(EscalationFailed::class);
});

it('fails an escalation at once on a permanent refusal', function (): void {
    now_('2026-11-06 10:00');
    chaser();
    published('flow', escalating([['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]]]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::definition('chase')->retire();

    $this->travelAndWake('P1D');

    expect(WorkflowEscalation::query()->first()?->error_code)->toBe('definition_not_active')
        ->and(WorkflowEscalation::query()->first()?->status)->toBe(EscalationStatus::Failed)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Waiting);
});
