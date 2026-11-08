<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Actions\Engine\FireEscalationAction;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;

/**
 * @param  array<string, mixed>  $config
 * @param  list<array<string, mixed>>  $escalations
 * @return array<string, mixed>
 */
function approvalFlow(array $config, array $escalations = [], bool $cancelledEdge = false): array
{
    $edges = [['start', 'approve'], ['approve', 'yes', 'approved'], ['approve', 'no', 'rejected'], ['approve', 'late', 'expired']];

    if ($cancelledEdge) {
        $edges[] = ['approve', 'off', 'cancelled'];
    }

    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', $config, $escalations === [] ? [] : ['escalations' => $escalations]),
        node('yes', 'end'), node('no', 'end'), node('late', 'end'),
        ...($cancelledEdge ? [node('off', 'end')] : []),
    ], $edges, ['timezone' => 'UTC']);
}

/**
 * @return array{0: Team, 1: PurchaseRequest, 2: WorkflowInstance}
 */
function approving(array $config, array $members = [], array $attributes = [], array $escalations = [], bool $cancelledEdge = false): array
{
    $team = team('Acme', $members);
    published('approve', approvalFlow($config, $escalations, $cancelledEdge), $team, 'purchase_request');
    $request = purchase($team, $attributes);
    $instance = Workflows::start('approve', $request, $team)->dispatch()->instance ?? throw new LogicException;

    return [$team, $request, $instance];
}

it('opens a flat round and takes its outcome', function (string $decision, array $path): void {
    $approver = user('Adam');
    [, $request, $instance] = approving(['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any'], ['user' => $approver], ['approver_id' => $approver->id]);
    $round = ApprovalRequest::query()->sole();

    expect($instance)->toBeWaitingAt('approve')
        ->and($instance->stepRuns()->where('node_id', 'approve')->sole()->output['request_id'])->toBe($round->id);

    Approvals::as($approver)->for($request)->within($round)->{$decision}();

    expect($instance->refresh())->toHaveCompletedVia($path);
})->with([
    'approved' => ['approve', ['start', 'approve', 'yes']],
    'rejected' => ['reject', ['start', 'approve', 'no']],
]);

// C-60: parties that resolve to nobody never let anyone decide.
it('fails instead of letting anyone decide when the approvers resolve to nobody', function (): void {
    [, , $instance] = approving(['stages' => [['name' => 'Legal', 'approvers' => [['kind' => 'role', 'role' => 'legal']]]]]);

    expect($instance)->toHaveFailedWith('no_approvers')
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

// C-52: a round resolved before its wait rows exist is caught by the probe.
it('catches a round resolved before the wait exists with the probe', function (): void {
    $approver = user('Adam');
    // The round resolves inside open(), before the step has recorded its wait.
    Event::listen(ApprovalStageOpened::class, static function (ApprovalStageOpened $event) use ($approver): void {
        $request = $event->stage->request;

        if ($request instanceof ApprovalRequest && $request->subject !== null) {
            Approvals::as($approver)->for($request->subject)->within($request)->approve();
        }
    });

    [, , $instance] = approving(['stages' => [['approvers' => [['kind' => 'field', 'field' => 'subject.approver']]]]], ['user' => $approver], ['approver_id' => $approver->id]);

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'yes']);
});

// C-147: a decision that rolls back resumes nothing.
it('resumes nothing on an approval decision that rolls back', function (): void {
    $approver = user('Adam');
    [, $request, $instance] = approving(['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any'], ['user' => $approver], ['approver_id' => $approver->id]);

    try {
        DB::transaction(function () use ($approver, $request): void {
            Approvals::as($approver)->for($request)->within(ApprovalRequest::query()->sole())->approve();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($instance->refresh())->toBeWaitingAt('approve')
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);
});

// OQ-22
it('takes the cancelled branch, or fails, when the round is closed outside the workflow', function (bool $edge, string $expected): void {
    $approver = user('Adam');
    [, $request, $instance] = approving(['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any'], ['user' => $approver], ['approver_id' => $approver->id], cancelledEdge: $edge);

    Approvals::for($request)->within(ApprovalRequest::query()->sole())->close(ApprovalStatus::Cancelled);

    $edge
        ? expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'off'])
        : expect($instance->refresh())->toHaveFailedWith($expected);
})->with([
    'with an edge' => [true, ''],
    'without' => [false, 'approval_cancelled'],
]);

// C-56: cancelling the instance closes its round, whose resolution then resumes nothing.
it('closes the round when the instance is cancelled', function (): void {
    $approver = user('Adam');
    [, , $instance] = approving(['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any'], ['user' => $approver], ['approver_id' => $approver->id]);

    Workflows::instances()->cancel($instance);

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Cancelled);
});

it('expires the round at its expiry through the safety-net timer', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    $approver = user('Adam');
    [, , $instance] = approving(['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any', 'expires_in' => 'P1D'], ['user' => $approver], ['approver_id' => $approver->id]);

    $this->travelAndWake(CarbonImmutable::parse('2026-11-03 09:04', 'UTC'));
    expect($instance->refresh())->toBeWaitingAt('approve');

    $this->travelAndWake(CarbonImmutable::parse('2026-11-03 09:05', 'UTC'));

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'late'])
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Expired);
});

// C-146: reassign retires the waits first, keeps the expiry, skips cleared stages, opens once.
it('replaces a staged round without signalling itself', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    $lawyer = user('Lea');
    $cfo = user('Carl');
    $deputy = user('Dora', []);
    $cfo->update(['manager_id' => $deputy->id]);

    [, $request, $instance] = approving([
        'stages' => [
            ['name' => 'Legal', 'approvers' => [['kind' => 'role', 'role' => 'legal']]],
            ['name' => 'CFO', 'approvers' => [['kind' => 'role', 'role' => 'cfo']]],
        ],
        'expires_in' => 'P5D',
    ], ['legal' => $lawyer, 'cfo' => $cfo, 'user' => $deputy], escalations: [
        ['after' => 'P1D', 'actions' => [['type' => 'reassign', 'to' => [['kind' => 'user', 'id' => $deputy->id]]]]],
    ]);

    $first = ApprovalRequest::query()->sole();
    Approvals::as($lawyer)->for($request)->within($first)->approve();

    $this->travelAndWake('P1D');

    $rounds = ApprovalRequest::query()->orderBy('id')->get();
    $second = $rounds->last() ?? throw new LogicException;
    $run = $instance->stepRuns()->where('node_id', 'approve')->sole();

    expect($rounds)->toHaveCount(2)
        ->and($first->refresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and($second->stages()->pluck('name')->all())->toBe(['CFO'])
        ->and(array_column($second->stages()->sole()->namedApprovers(), 'id'))->toBe([$deputy->id])
        ->and($second->expires_at?->equalTo($first->expires_at))->toBeTrue()
        ->and($run->output['request_id'])->toBe($second->id)
        ->and($run->output['rounds'])->toBe([$first->id, $second->id])
        ->and($instance->refresh())->toBeWaitingAt('approve')
        ->and($run->waits()->where('status', WaitStatus::Pending->value)->pluck('event_key')->filter()->values()->all())->toBe(['@approvals.request.'.$second->id]);

    // A retried escalation opens no second round.
    $escalation = $instance->escalations()->where('level', 1)->sole();
    $escalation->update(['status' => 'pending']);
    app(FireEscalationAction::class)->execute($escalation->id);

    expect(ApprovalRequest::query()->count())->toBe(2);

    Approvals::as($deputy)->for($request)->within($second)->approve();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'yes']);
});

it('refuses a reassign on a type without it and validates approval configs', function (): void {
    $team = team();
    $report = Workflows::versions()->validate(approvalFlow([]), 'purchase_request', $team);
    $both = Workflows::versions()->validate(approvalFlow(['approvers' => [['kind' => 'triggered_by']], 'stages' => [['approvers' => [['kind' => 'triggered_by']]]]]), 'purchase_request', $team);
    $badParty = Workflows::versions()->validate(approvalFlow(['approvers' => [['kind' => 'field', 'field' => 'subject.amount']]]), 'purchase_request', $team);
    $preset = Workflows::versions()->validate(approvalFlow(['preset' => 'payout', 'approvers' => [[['kind' => 'field', 'field' => 'subject.nope']]]]), 'purchase_request', $team);

    expect($report->has(ValidationCode::InvalidConfig, 'approve'))->toBeTrue()
        ->and($both->has(ValidationCode::InvalidConfig, 'approve'))->toBeTrue()
        ->and($badParty->has(ValidationCode::InvalidValue, 'approve'))->toBeTrue()
        ->and($preset->has(ValidationCode::UnknownField, 'approve'))->toBeTrue();
});

it('opens a round from an approvals preset', function (): void {
    config()->set('approvals.workflows', ['payout' => ['stages' => [['name' => 'Finance', 'rule' => 'any', 'required_approvers' => 1]]]]);
    $approver = user('Adam');

    [, $request, $instance] = approving(['preset' => 'payout', 'approvers' => [[['kind' => 'field', 'field' => 'subject.approver']]]], ['user' => $approver], ['approver_id' => $approver->id]);

    $round = ApprovalRequest::query()->sole();

    expect($round->workflow)->toBe('payout');

    Approvals::as($approver)->for($request)->within($round)->approve();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'yes']);
});

it('maps an unknown preset to a permanent failure', function (): void {
    $approver = user('Adam');

    [, , $instance] = approving(['preset' => 'nope', 'approvers' => [[['kind' => 'field', 'field' => 'subject.approver']]]], ['user' => $approver], ['approver_id' => $approver->id]);

    expect($instance)->toHaveFailedWith('unknown_approval_preset');
});

it('is listed as "would run" by a dry run', function (): void {
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    published('approve', approvalFlow(['approvers' => [['kind' => 'triggered_by']], 'rule' => 'any']), $team, 'purchase_request');

    $result = Workflows::definition('approve', $team)->dryRun()->subject(purchase($team))->assume(['approve' => 'rejected'])->run();

    expect($result->nodes())->toBe(['start', 'approve', 'no'])
        ->and($result->actions[0]->status)->toBe('would_run');
});
