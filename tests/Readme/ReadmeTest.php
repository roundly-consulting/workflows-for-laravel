<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\Party;
use RoundlyConsulting\Workflows\Graph\Build\Trigger;
use RoundlyConsulting\Workflows\Graph\Build\When;
use RoundlyConsulting\Workflows\Tests\Readme\Fixtures\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Readme\ReadmeTestCase;

// The README's one usage example, as written, on a host that followed the README's Installation
// only (chat review C-9): the bridges it installs, the default database queue and its worker.

it('runs the README example', function (): void {
    $admin = user('Ada');
    $requester = user('Rita');
    $approver = user('Adam');
    $team = company('Acme');
    $request = PurchaseRequest::query()->create(['team_id' => $team->id, 'amount' => '1000', 'requester_id' => $requester->id, 'approver_id' => $approver->id]);

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

    $result->instance->status;   // InstanceStatus::Running — a queue worker runs it on to the approval step, where it waits
    // --- /README ---

    expect($definition->key)->toBe('purchase-approval')
        ->and($result->created)->toBeTrue()
        ->and($result->instance?->status)->toBe(InstanceStatus::Running);

    // The worker the README's queue implies.
    $this->runWorkflows();

    expect($result->instance?->refresh()->status)->toBe(InstanceStatus::Waiting)
        ->and($result->instance?->current_node_id)->toBe('approve');
});

it('keeps the README block identical to the example this test runs', function (): void {
    preg_match('/## Usage.*?```php\n(.*?)```/s', ReadmeTestCase::readme(), $readme);
    preg_match('#// --- README ---\n(.*?)\n\s*// --- /README ---#s', (string) file_get_contents(__FILE__), $test);

    $body = static fn (string $code): string => trim((string) preg_replace('/^\s+/m', '', (string) preg_replace('/^use .*;$/m', '', $code)));

    expect($body($readme[1] ?? ''))->toBe($body($test[1] ?? ''))
        ->and(substr_count($readme[1] ?? '', "\n"))->toBeLessThanOrEqual(40);
});

it('installs every Roundly package the example uses', function (): void {
    preg_match('/## Usage.*?```php\n(.*?)```/s', ReadmeTestCase::readme(), $readme);

    expect(str_contains($readme[1] ?? '', '->approval('))->toBeTrue()
        ->and(ReadmeTestCase::installedBridges())->toContain('approvals')
        ->and(ReadmeTestCase::installation())->toContain('php artisan vendor:publish --tag="approvals-migrations"');
});
