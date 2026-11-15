<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\NotificationContexts;

afterEach(function (): void {
    NotificationContexts::$returns = null;
});

/**
 * A team-owned flow notifying the purchase's requester, and its started instance.
 *
 * @return array{0: Team, 1: WorkflowInstance}
 */
function notifiedTeam(): array
{
    $rita = user('Rita');
    $team = team('Acme', ['user' => $rita]);
    published('tell', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('tell', 'notify', ['notification' => 'purchase.approved', 'recipients' => [['kind' => 'field', 'field' => 'subject.requester']]]),
        node('done', 'end'),
    ], [['start', 'tell'], ['tell', 'done']]), $team, 'purchase_request');

    $instance = Workflows::start('tell', purchase($team, ['requester_id' => $rita->id]), $team)->dispatch()->instance ?? throw new LogicException;

    return [$team, $instance];
}

it('files a notify step\'s inbox item under the instance owner by default', function (): void {
    [$team, $instance] = notifiedTeam();
    $item = InboxItem::query()->sole();

    expect($instance->status->value)->toBe('completed')
        ->and($item->context_type)->toBe($team->getMorphClass())
        ->and((string) $item->context_id)->toBe((string) $team->getKey())
        ->and($item->context_label)->toBe('Acme');
});

it('keeps the owner default for a config published before the key existed', function (): void {
    config()->set('workflows.bridges', ['lifecycle' => ['emit_transitions' => 'off']]);
    [$team] = notifiedTeam();

    expect((string) InboxItem::query()->sole()->context_id)->toBe((string) $team->getKey());
});

it('sends without a context when it is switched off', function (mixed $off): void {
    config()->set('workflows.bridges.notifications.context', $off);
    notifiedTeam();

    $item = InboxItem::query()->sole();

    expect($item->context_type)->toBeNull()
        ->and($item->context_key)->toBeNull();
})->with(['off' => 'off', 'none' => 'none', 'null' => null, 'false' => false]);

it('uses the configured context resolver', function (): void {
    config()->set('workflows.bridges.notifications.context', NotificationContexts::class);
    notifiedTeam();

    expect(InboxItem::query()->sole()->context_key)->toBe('workflows')
        ->and(InboxItem::query()->sole()->context_label)->toBe('Workflow tell');
});

it('refuses a resolver that returns something no notification context can be', function (): void {
    config()->set('workflows.bridges.notifications.context', NotificationContexts::class);
    NotificationContexts::$returns = new stdClass;

    [, $instance] = notifiedTeam();

    expect($instance->refresh()->status->value)->not->toBe('completed')
        ->and($instance->stepRuns()->where('node_id', 'tell')->first()?->error_code)->toBe('exception')
        ->and(InboxItem::query()->count())->toBe(0);
});

it('sends without a context when the owner key does not fit the notifications context columns', function (): void {
    config()->set('notifications.context_key_type', 'uuid');
    [, $instance] = notifiedTeam();

    expect($instance->status->value)->toBe('completed')
        ->and(InboxItem::query()->sole()->context_type)->toBeNull();
});

it('files a notify escalation under the owner too', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    $rita = user('Rita');
    $team = team('Acme', ['user' => $rita]);
    published('chase', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'purchase.paid'], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'notify', 'notification' => 'purchase.escalated', 'recipients' => [['kind' => 'field', 'field' => 'subject.requester']]]]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]), $team, 'purchase_request');

    Workflows::start('chase', purchase($team, ['requester_id' => $rita->id]), $team)->dispatch();
    $this->travelAndWake('PT1H');

    expect((string) InboxItem::query()->sole()->context_id)->toBe((string) $team->getKey());
});
