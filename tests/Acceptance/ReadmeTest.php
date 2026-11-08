<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;

// The README's one usage example, as written.
it('runs the README example', function (): void {
    $admin = user('Ada');
    $requester = user('Rita');
    $approver = user('Adam');
    $team = team('Acme', ['admin' => $admin, 'user' => $approver]);
    $request = purchase($team, ['requester_id' => $requester->id, 'approver_id' => $approver->id]);
    $json = json_encode(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('check', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '500']]),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']),
        node('done', 'end'),
    ], [['start', 'check'], ['check', 'approve', 'true'], ['check', 'done', 'false'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']]), JSON_THROW_ON_ERROR);

    // --- README ---
    // Once, in a service provider: what a purchase request looks like to the engine.
    Workflows::subjects()->register(
        Workflows::subject('purchase_request', PurchaseRequest::class)
            ->label('Purchase request')
            ->ownedBy('team')
            ->field('amount', 'decimal', 'Amount')
            ->field('requester', 'party', 'Requester')
            ->field('approver', 'party', 'Approver'),
    );

    // The workflow itself is data: a graph your users edit in a builder, stored as JSON.
    $definition = Workflows::definitions()->create(key: 'purchase-approval', name: 'Purchase approval',
        subjectType: 'purchase_request', owner: $team);

    Workflows::definition('purchase-approval', $team)->edit(json_decode($json, true));
    Workflows::definition('purchase-approval', $team)->publish(by: $admin);

    // Start it (this graph's trigger is `manual`) — with an `event` trigger the app event starts it instead.
    $result = Workflows::start('purchase-approval', subject: $request, owner: $team)
        ->by($request->requester)
        ->idempotencyKey("submit:{$request->id}")
        ->dispatch();

    $result->instance->status;   // InstanceStatus::Waiting — at the approval step
    // --- /README ---

    expect($definition?->key)->toBe('purchase-approval')
        ->and($result->created)->toBeTrue()
        ->and($result->instance?->status)->toBe(InstanceStatus::Waiting)
        ->and($result->instance?->current_node_id)->toBe('approve');
});
