<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;

// Dual review F-10: a round an approval attempt opened before it failed is closed when the
// instance was cancelled meanwhile.

it('closes the round of an approval attempt that failed while the instance was being cancelled', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    Bus::fake();
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    published('approve', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']]), $team, 'purchase_request');

    $instance = Workflows::start('approve', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch()->instance ?? throw new LogicException;
    $run = StepRun::query()->where('node_id', 'approve')->sole();
    $context = app(RestoresOwnerContext::class);
    $claim = $context->run($team, fn () => (fn (string|int $instance, string|int $run) => $this->claim($instance, $run))->call(app(RunStepAction::class), $instance->id, $run->id));

    // The cancel lands first; the attempt then opens the round (once('round')) and fails.
    Workflows::instances()->cancel($instance);
    $context->run($team, fn () => app(Attempts::class)->run($claim->instance, $claim->run));
    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);

    $context->run($team, fn () => app(RecordStepResultAction::class)->execute($instance->id, $run->id, $claim->token, StepFailure::transient(FailureCode::Exception, 'after opening')));

    expect(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled);
});
