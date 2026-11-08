<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\Party;
use RoundlyConsulting\Workflows\Graph\Build\Trigger;
use RoundlyConsulting\Workflows\Graph\Build\When;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;

// The README's one usage example, as written (and its block must still parse).
it('runs the README example', function (): void {
    $admin = user('Ada');
    $requester = user('Rita');
    $approver = user('Adam');
    $team = team('Acme', ['admin' => $admin, 'user' => $approver]);
    $request = purchase($team, ['requester_id' => $requester->id, 'approver_id' => $approver->id]);

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

    // The workflow itself is data: a JSON graph your users edit in a builder — or written in PHP.
    $definition = Workflows::definitions()->create(key: 'purchase-approval', name: 'Purchase approval',
        subjectType: 'purchase_request', owner: $team);

    Workflows::definition('purchase-approval', $team)->edit(Workflows::graph()   // or ->edit(json_decode($json, true))
        ->trigger('start', Trigger::manual())
        ->condition('check', When::field('subject.amount')->gt('500'))
        ->approval('approve', approvers: [Party::field('subject.approver')])
        ->end('done')
        ->connect('start', 'check')
        ->connect('check', 'approve', on: 'true')
        ->connect('check', 'done', on: 'false')
        ->connect('approve', 'done', on: ['approved', 'rejected', 'expired']));
    Workflows::definition('purchase-approval', $team)->publish(by: $admin);

    // Start it (this graph's trigger is `manual`) — with an `event` trigger the app event starts it instead.
    $result = Workflows::start('purchase-approval', subject: $request, owner: $team)
        ->by($request->requester)
        ->idempotencyKey("submit:{$request->id}")
        ->dispatch();

    $result->instance->status;   // InstanceStatus::Waiting — at the approval step
    // --- /README ---

    expect($definition->key)->toBe('purchase-approval')
        ->and($result->created)->toBeTrue()
        ->and($result->instance?->status)->toBe(InstanceStatus::Waiting)
        ->and($result->instance?->current_node_id)->toBe('approve');
});

it('keeps the README block in step with this test', function (): void {
    preg_match('/## Usage.*?```php\n(.*?)```/s', (string) file_get_contents(__DIR__.'/../../README.md'), $match);
    $readme = $match[1] ?? '';

    expect($readme)->toContain('Workflows::graph()')
        ->and($readme)->toContain("->connect('approve', 'done', on: ['approved', 'rejected', 'expired']));")
        ->and(substr_count($readme, "\n"))->toBeLessThanOrEqual(40);
});
