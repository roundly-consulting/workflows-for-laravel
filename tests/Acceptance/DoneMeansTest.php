<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Events\EscalationTriggered;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Workflows\WorkflowsManager;

function bratislava(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Bratislava')->utc();
}

function inbox(User $user, string $type): int
{
    return InboxItem::query()->where('recipient_type', $user->getMorphClass())->where('recipient_id', $user->id)->where('type', $type)->count();
}

/**
 * The "Done means" test app: users, a team, a team-scoped purchase request with a lifecycle and
 * notification types — and the workflow purely as JSON.
 *
 * @return array{0: PurchaseRequest, 1: User, 2: User, 3: User, 4: Team}
 */
function purchaseApprovalApp(): array
{
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');

    $manager = user('Maria');
    $approver = user('Adam', ['manager_id' => $manager->id]);
    $requester = user('Rita');
    $team = team('Acme', ['admin' => $requester, 'user' => $approver]);
    Teams::for($team)->members()->add($manager, 'user');

    Workflows::definitions()->create(key: 'purchase-approval', name: 'Purchase approval', subjectType: 'purchase_request', owner: $team);
    Workflows::definition('purchase-approval', $team)->edit(workflowJson('purchase-approval'));
    Workflows::definition('purchase-approval', $team)->publish(by: $requester);

    $request = purchase($team, ['requester_id' => $requester->id, 'approver_id' => $approver->id]);

    return [$request, $requester, $approver, $manager, $team];
}

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// D-1: "approval → delay 2 business days → escalate to manager → notify requester", purely as
// JSON, published, run end to end on queues (the database driver, real serialization) with
// faked time.
it('runs the JSON purchase workflow end to end on queues', function (): void {
    Event::fake([EscalationTriggered::class]);
    $this->travelTo(bratislava('2026-11-06 10:00'));   // Friday
    [$request, $requester, $approver, $manager, $team] = purchaseApprovalApp();

    Workflows::emit('purchase_request.submitted', $request, idempotencyKey: 'submit:'.$request->id);
    $this->runWorkflows();

    $instance = WorkflowInstance::query()->sole();
    $round = ApprovalRequest::query()->latest('id')->firstOrFail();

    expect($instance)->toBeWaitingAt('approve')
        ->and(array_column($round->namedApprovers(), 'id'))->toBe([$approver->id])
        ->and(DB::table('jobs')->count())->toBe(0);

    $this->travelAndWake(bratislava('2026-11-09 10:00'));    // Monday: one business day
    Event::assertNotDispatched(EscalationTriggered::class);

    $this->travelAndWake(bratislava('2026-11-10 10:00'));    // Tuesday: two business days
    $this->runWorkflows();

    $rounds = ApprovalRequest::query()->orderBy('id')->get();
    $escalated = $rounds->last() ?? throw new LogicException;

    expect($rounds->first()?->status)->toBe(ApprovalStatus::Cancelled)
        ->and(array_column($escalated->namedApprovers(), 'id'))->toBe([$manager->id])
        ->and($escalated->expires_at?->equalTo($rounds->first()?->expires_at))->toBeTrue()
        ->and(inbox($requester, 'purchase.escalated'))->toBe(1)
        ->and($instance->refresh())->toBeWaitingAt('approve');

    Event::assertDispatched(EscalationTriggered::class, fn (EscalationTriggered $event): bool => $event->level === 1 && $event->actor->is($instance));

    // The manager approves the escalated round.
    Approvals::as($manager)->for($request)->within($escalated)->approve();
    $this->runWorkflows();

    expect(Teams::runAs($team, fn () => $request->refresh()->state))->toBe('approved')
        ->and(inbox($requester, 'purchase.approved'))->toBe(1)
        ->and($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'mark_approved', 'tell_approved', 'done']);
});

it('expires after five business days', function (): void {
    $this->travelTo(bratislava('2026-11-06 10:00'));
    [$request, $requester] = purchaseApprovalApp();

    Workflows::emit('purchase_request.submitted', $request);
    $this->runWorkflows();

    // Escalated on Tuesday, still unanswered on the next Friday at 10:05 (5 business days + grace).
    $this->travelAndWake(bratislava('2026-11-10 10:00'));
    $this->travelAndWake(bratislava('2026-11-13 10:04'));

    expect(WorkflowInstance::query()->sole())->toBeWaitingAt('approve');

    $this->travelAndWake(bratislava('2026-11-13 10:05'));
    $this->runWorkflows();

    expect(WorkflowInstance::query()->sole())->toHaveCompletedVia(['start', 'approve', 'tell_expired', 'done'])
        ->and(inbox($requester, 'purchase.expired'))->toBe(1);
});

it('survives a restart while escalated', function (): void {
    $this->travelTo(bratislava('2026-11-06 10:00'));
    [$request, $requester, $approver, $manager] = purchaseApprovalApp();

    Workflows::emit('purchase_request.submitted', $request);
    $this->runWorkflows();
    $this->travelAndWake(bratislava('2026-11-10 10:00'));

    // The restart: the queue is flushed and every singleton forgotten.
    DB::table('jobs')->delete();
    app()->forgetInstance(WorkflowsManager::class);
    app()->forgetInstance(StepCatalogue::class);

    $escalated = ApprovalRequest::query()->latest('id')->firstOrFail();
    Approvals::as($manager)->for($request)->within($escalated)->reject();

    // The resolution's job was lost with the queue: the expiry safety net still finishes it,
    // and the lost-job requeue would too. Here the signal job is simply re-run by a fresh worker.
    $this->runWorkflows();

    expect(WorkflowInstance::query()->sole())->toHaveCompletedVia(['start', 'approve', 'mark_rejected', 'tell_rejected', 'done'])
        ->and(inbox($requester, 'purchase.rejected'))->toBe(1);
});
