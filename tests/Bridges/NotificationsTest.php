<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Bridges;

function notifyGraph(array $config): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('tell', 'notify', $config),
        node('done', 'end'),
    ], [['start', 'tell'], ['tell', 'done']]);
}

// C-61
it('completes with zero recipients when there is nobody to notify', function (): void {
    published('tell', notifyGraph(['notification' => 'document.notice', 'recipients' => [['kind' => 'field', 'field' => 'subject.approver']]]));

    $instance = Workflows::start('tell', document())->dispatch()->instance;

    expect($instance?->status->value)->toBe('completed')
        ->and($instance?->stepRuns()->where('node_id', 'tell')->sole()->output)->toBe(['recipients' => 0])
        ->and(InboxItem::query()->count())->toBe(0);
});

// C-62
it('deduplicates parties and skips deleted users', function (): void {
    $rita = user('Rita');
    $gone = user('Gone');
    $gone->delete();
    published('tell', notifyGraph(['notification' => 'document.notice', 'recipients' => [
        ['kind' => 'field', 'field' => 'subject.requester'],
        ['kind' => 'user', 'id' => $rita->id],
        ['kind' => 'user', 'id' => $gone->id],
        ['kind' => 'user', 'id' => 999],
    ], 'data' => ['note' => ['ref' => 'subject.title']], 'actor' => 'triggered_by']));

    // The starter is the notification's actor (never notified themself), Rita the one recipient.
    $instance = Workflows::start('tell', document(null, ['requester_id' => $rita->id, 'title' => 'Hello']))->by(user('Boss'))->dispatch()->instance;

    expect($instance?->stepRuns()->where('node_id', 'tell')->sole()->output)->toBe(['recipients' => 1])
        ->and(InboxItem::query()->sole()->recipient_id)->toBe($rita->id)
        ->and(InboxItem::query()->sole()->data['note'] ?? null)->toBe('Hello');
});

it('refuses a type without an access check for good, without retrying', function (): void {
    published('tell', notifyGraph(['notification' => 'document.unchecked', 'recipients' => [['kind' => 'triggered_by']]]));

    $instance = Workflows::start('tell', document())->by(user())->dispatch()->instance;

    expect($instance)->toHaveFailedWith('notification_refused:missing_access_check')
        ->and($instance?->stepRuns()->where('node_id', 'tell')->count())->toBe(1);
});

it('validates the notification type and recipients at publish', function (): void {
    $report = Workflows::versions()->validate(notifyGraph(['notification' => 'nope', 'recipients' => [['kind' => 'field', 'field' => 'subject.nope']]]), 'document');

    expect($report->has(ValidationCode::UnknownNotificationType, 'tell'))->toBeTrue()
        ->and($report->has(ValidationCode::UnknownField, 'tell'))->toBeTrue();
});

it('notifies as an escalation action, once per key', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    $rita = user('Rita');
    published('wait', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'notify', 'notification' => 'document.notice', 'recipients' => [['kind' => 'field', 'field' => 'subject.requester']]]]]]]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]));

    Workflows::start('wait', document(null, ['requester_id' => $rita->id]))->dispatch();
    $this->travelAndWake('PT1H');

    expect(InboxItem::query()->count())->toBe(1);
});

it('simulates a notify step in a dry run', function (): void {
    published('tell', notifyGraph(['notification' => 'document.notice', 'recipients' => [['kind' => 'triggered_by']]]));

    $result = Workflows::definition('tell')->dryRun()->subject(document())->run();

    expect($result->actions[0]->description)->toBe('notify document.notice');
});

// W-07: party list validation.
it('validates the recipient list of a notify step', function (array $recipients, ValidationCode $code): void {
    $report = Workflows::versions()->validate(notifyGraph(['notification' => 'document.notice', 'recipients' => $recipients]), 'document');

    expect($report->has($code, 'tell'))->toBeTrue();
})->with([
    'empty' => [[], ValidationCode::InvalidConfig],
    'field of a non-party field' => [[['kind' => 'field', 'field' => 'subject.title']], ValidationCode::InvalidValue],
    'not a party' => [['x'], ValidationCode::InvalidConfig],
]);

it('rejects a role party when no roles resolver is available', function (): void {
    Bridges::pretendMissing(['teams']);

    $report = Workflows::versions()->validate(notifyGraph(['notification' => 'document.notice', 'recipients' => [['kind' => 'role', 'role' => 'legal']]]), 'document');

    expect($report->has(ValidationCode::RolesUnavailable, 'tell'))->toBeTrue();
});
