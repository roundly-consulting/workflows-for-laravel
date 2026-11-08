<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;

// Dual review O-13: cancelling an approval step between attempts closes the round it opened.

it('closes the round of an approval step cancelled while its retry is queued', function (): void {
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    published('approve', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']]), $team, 'purchase_request');

    $instance = Workflows::start('approve', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch()->instance ?? throw new LogicException;
    $first = StepRun::query()->where('node_id', 'approve')->sole();
    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);

    // The attempt failed retryably; its retry waits in the backoff, without output of its own yet.
    $first->status = StepRunStatus::Failed;
    $first->save();
    $retry = $first->replicate(['claim_token']);
    $retry->attempt = 2;
    $retry->status = StepRunStatus::Queued;
    $retry->output = null;
    $retry->save();

    Workflows::instances()->cancel($instance);

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled);
});
