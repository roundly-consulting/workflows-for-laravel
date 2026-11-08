<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

// Chat review C-4: a team-owned `for_each` schedule scans its subjects (and evaluates `where`)
// inside the team's context, like the webhook subject lookup (dual review O-4). `workflows:wake`
// runs with no team context at all.

function scheduleClock(string $at): void
{
    Carbon::setTestNow(CarbonImmutable::parse($at, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'UTC'));
}

it('starts one instance per matching team subject with no ambient team context', function (string $mode): void {
    config()->set('teams.scoping.without_context', $mode);
    scheduleClock('2026-11-09 08:00:00');

    $acme = team('Acme');
    $globex = team('Globex');
    $big = purchase($acme, ['amount' => '1000']);
    $bigger = purchase($acme, ['amount' => '5000']);
    purchase($acme, ['amount' => '100']);
    purchase($globex, ['amount' => '9000']);

    published('chase', graph([
        node('start', 'trigger', ['trigger' => 'schedule', 'cron' => '0 9 * * *', 'timezone' => 'UTC', 'for_each' => [
            'where' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '500'],
            'limit' => 10,
        ]]),
        node('done', 'end'),
    ], [['start', 'done']]), $acme, 'purchase_request');

    scheduleClock('2026-11-09 09:00:00');

    expect(Workflows::wake()->schedules)->toBe(2)
        ->and(WorkflowInstance::query()->orderBy('subject_id')->pluck('subject_id')->all())->toBe([$big->id, $bigger->id]);
})->with(['empty', 'throw']);
