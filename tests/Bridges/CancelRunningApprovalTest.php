<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\RunStepAction;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Support\Engine\Attempts;

// Dual review F-8: the round a running first attempt opened is closed when the instance was
// cancelled before the attempt recorded it.

it('closes the round an approval step opened while the instance was being cancelled', function (): void {
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

    // The worker claims the attempt; its handle() opens the round …
    $context = app(RestoresOwnerContext::class);
    $claim = $context->run($team, fn () => (fn (string|int $instance, string|int $run) => $this->claim($instance, $run))->call(app(RunStepAction::class), $instance->id, $run->id));
    $outcome = $context->run($team, fn () => app(Attempts::class)->run($claim->instance, $claim->run));
    expect($outcome->result?->output['request_id'] ?? null)->not->toBeNull()
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Pending);

    // … the instance is cancelled before the attempt records it …
    Workflows::instances()->cancel($instance);

    // … and the discarded record still closes the round.
    $context->run($team, fn () => app(RecordStepResultAction::class)->execute($instance->id, $run->id, $claim->token, $outcome->result, $outcome->transactional));

    expect($instance->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and(ApprovalRequest::query()->sole()->status)->toBe(ApprovalStatus::Cancelled);
});
