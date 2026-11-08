<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;

// Dual review F-8 residual: a round whose attempt failed before recording any output is still
// closed on cancel — found through the step's once('round') record.

it('closes a round the step opened but never recorded', function (): void {
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    published('approve', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']]), $team, 'purchase_request');

    $instance = Workflows::start('approve', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch()->instance ?? throw new LogicException;

    // handle() opened the round (once('round') committed), then threw: no output was recorded.
    $first = StepRun::query()->where('node_id', 'approve')->sole();
    $first->status = StepRunStatus::Failed;
    $first->output = null;
    $first->save();
    $retry = $first->replicate(['claim_token']);
    $retry->attempt = 2;
    $retry->status = StepRunStatus::Queued;
    $retry->save();

    Workflows::instances()->cancel($instance);

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled);
});

it('leaves a round decided meanwhile alone', function (): void {
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    published('approve', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']]), $team, 'purchase_request');

    $instance = Workflows::start('approve', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch()->instance ?? throw new LogicException;
    $first = StepRun::query()->where('node_id', 'approve')->sole();
    $first->output = null;
    $first->save();
    ApprovalRequest::query()->update(['status' => ApprovalStatus::Approved->value]);

    Workflows::instances()->cancel($instance);

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Approved);
});
