<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\Trigger;
use RoundlyConsulting\Workflows\Graph\Build\When;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Schedule\CronExpression;

// W-27: `composer mutate` mutates the pure core these tests pin.
mutates(CronExpression::class);

function scheduled(string $cron, string $timezone = 'UTC'): array
{
    return graph([node('start', 'trigger', ['trigger' => 'schedule', 'cron' => $cron, 'timezone' => $timezone]), node('done', 'end')], [['start', 'done']]);
}

function clockAt(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
}

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// C-103: catch-up coalesces, a repeated DST hour runs once, a skipped hour does not run, and a
// version published mid-minute never double-starts.
it('starts each due slot once and coalesces missed ones', function (): void {
    clockAt('2026-11-09 08:00:00 UTC');
    published('daily', scheduled('*/10 * * * *'), null, null);

    clockAt('2026-11-09 08:05:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(0);

    clockAt('2026-11-09 08:10:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(1);

    // Same minute again: the key is per local slot.
    expect(Workflows::wake()->schedules)->toBe(0);

    // Down for 40 minutes: only the latest missed slot starts.
    clockAt('2026-11-09 08:52:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(1)
        ->and(WorkflowInstance::query()->get()->map(fn (WorkflowInstance $instance): mixed => $instance->trigger_payload['trigger']['slot'] ?? null)->all())
        ->toBe(['2026-11-09T08:10', '2026-11-09T08:50']);

    // Down for more than the catch-up window: anything older than an hour is gone.
    clockAt('2026-11-09 11:31:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(1);

    // v2 published mid-minute: the slot already ran.
    clockAt('2026-11-09 11:31:30 UTC');
    Workflows::definition('daily')->edit(scheduled('*/10 * * * *'));
    Workflows::definition('daily')->publish();

    expect(Workflows::wake()->schedules)->toBe(0)
        ->and(WorkflowInstance::query()->count())->toBe(3);
});

it('runs a repeated DST hour once and skips a missing one', function (): void {
    clockAt('2026-10-25 00:00:00 UTC');
    published('night', scheduled('30 2 * * *', 'Europe/Bratislava'), null, null);

    // 02:30 local happens twice on 2026-10-25: 00:30 UTC (+02:00) and 01:30 UTC (+01:00).
    clockAt('2026-10-25 00:30:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(1);

    clockAt('2026-10-25 01:30:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(0);

    // 02:30 local does not exist on 2027-03-28 (02:00 → 03:00).
    clockAt('2027-03-28 00:00:00 UTC');
    Workflows::definition('night')->edit(scheduled('30 2 * * *', 'Europe/Bratislava'));
    Workflows::definition('night')->publish();

    foreach (['00:30', '01:00', '01:30', '02:00'] as $time) {
        clockAt('2027-03-28 '.$time.':00 UTC');
        Workflows::wake();
    }

    expect(WorkflowInstance::query()->count())->toBe(1);
});

it('runs presets and skips retired definitions', function (): void {
    clockAt('2026-11-09 08:00:00 UTC');
    published('weekday', graph([node('start', 'trigger', ['trigger' => 'schedule', 'preset' => ['every' => 'weekday', 'at' => '09:00']]), node('done', 'end')], [['start', 'done']]), null, null);

    clockAt('2026-11-09 09:00:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(1);

    Workflows::definition('weekday')->retire();

    clockAt('2026-11-10 09:00:00 UTC');
    expect(Workflows::wake()->schedules)->toBe(0);
});

// W-24
it('starts one instance per matching subject per slot', function (): void {
    clockAt('2026-11-09 08:00:00 UTC');
    $acme = company('Acme');
    $urgent = document($acme, ['urgent' => true]);
    document($acme, ['urgent' => false]);
    document(company('Globex'), ['urgent' => true]);
    $later = document($acme, ['urgent' => true]);
    published('chase', graph([
        node('start', 'trigger', ['trigger' => 'schedule', 'cron' => '0 9 * * *', 'timezone' => 'UTC', 'for_each' => ['where' => ['field' => 'subject.urgent', 'op' => 'equals', 'value' => true], 'limit' => 5]]),
        node('done', 'end'),
    ], [['start', 'done']]), $acme);

    clockAt('2026-11-09 09:00:00 UTC');

    expect(Workflows::wake()->schedules)->toBe(2)
        ->and(WorkflowInstance::query()->orderBy('id')->pluck('subject_id')->all())->toBe([$urgent->id, $later->id])
        ->and(Workflows::wake()->schedules)->toBe(0);

    clockAt('2026-11-10 09:00:00 UTC');

    expect(Workflows::wake()->schedules)->toBe(2);
});

it('caps a per-subject schedule and refuses it without a subject type', function (): void {
    clockAt('2026-11-09 08:00:00 UTC');
    foreach (range(1, 3) as $ignored) {
        document();
    }
    published('chase', graph([
        node('start', 'trigger', ['trigger' => 'schedule', 'cron' => '0 9 * * *', 'for_each' => ['limit' => 2]]),
        node('done', 'end'),
    ], [['start', 'done']]));

    clockAt('2026-11-09 09:00:00 UTC');

    expect(Workflows::wake()->schedules)->toBe(2)
        ->and(Workflows::versions()->validate(graph([
            node('start', 'trigger', ['trigger' => 'schedule', 'cron' => '0 9 * * *', 'for_each' => ['limit' => 2]]),
            node('done', 'end'),
        ], [['start', 'done']]))->valid())->toBeFalse()
        ->and(Workflows::versions()->validate(graph([
            node('start', 'trigger', ['trigger' => 'manual', 'for_each' => ['limit' => 2]]),
            node('done', 'end'),
        ], [['start', 'done']]), 'document')->valid())->toBeFalse()
        ->and(Trigger::schedule('0 9 * * *')->forEach(When::field('subject.urgent')->equals(true), limit: 10)->toArray())
        ->toBe(['trigger' => 'schedule', 'cron' => '0 9 * * *', 'for_each' => ['where' => ['field' => 'subject.urgent', 'op' => 'equals', 'value' => true], 'limit' => 10]]);
});
