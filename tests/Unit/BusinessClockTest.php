<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Calendars\WeekdayCalendar;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;
use RoundlyConsulting\Workflows\Support\BusinessClock;

function weekdays(string $timezone = 'Europe/Bratislava'): BusinessClock
{
    return new BusinessClock(new WeekdayCalendar($timezone));
}

function local(string $at, string $timezone = 'Europe/Bratislava'): CarbonImmutable
{
    return CarbonImmutable::parse($at, $timezone)->utc();
}

it('adds business days at the same local time', function (string $from, int $days, string $expected): void {
    expect(weekdays()->addBusinessDays(local($from), $days)->setTimezone('Europe/Bratislava')->format('D Y-m-d H:i'))->toBe($expected);
})->with([
    'Fri + 2 → Tue' => ['2026-11-06 15:00', 2, 'Tue 2026-11-10 15:00'],
    'Sat + 2 → Tue' => ['2026-11-07 10:00', 2, 'Tue 2026-11-10 10:00'],
    'Mon + 1 → Tue' => ['2026-11-09 10:00', 1, 'Tue 2026-11-10 10:00'],
    'Mon + 5 → Mon' => ['2026-11-09 10:00', 5, 'Mon 2026-11-16 10:00'],
    'n = 0 is the start' => ['2026-11-07 10:00', 0, 'Sat 2026-11-07 10:00'],
]);

// C-63: a DST gap moves to the next valid instant; a repeated time takes its first occurrence.
it('lands on a valid instant across DST', function (): void {
    $clock = weekdays('America/New_York');

    // The US gap is Sun 2027-03-14: Fri 02:30 + 1 business day is Mon 02:30, which exists.
    $gap = $clock->addBusinessDays(CarbonImmutable::parse('2027-03-12 02:30', 'America/New_York')->utc(), 1);
    expect($gap->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2027-03-15 02:30');

    // Bratislava falls back on Sun 2026-10-25; Monday 02:30 exists once and is kept.
    $after = weekdays()->addBusinessDays(local('2026-10-23 02:30'), 1);
    expect($after->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-10-26 02:30');

    // A calendar whose business day starts in the gap hour: 2026-03-29 is the EU spring-forward day.
    $sunday = new BusinessClock(new WeekdayCalendar('Europe/Bratislava', [7]));
    $landed = $sunday->addBusinessDays(local('2026-03-22 02:30'), 1);
    expect($landed->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe('2026-03-29 03:30');

    // A calendar whose business day holds the repeated hour: the first occurrence (+02:00).
    $fallBack = $sunday->addBusinessDays(local('2026-10-18 02:30'), 1);
    expect($fallBack->setTimezone('Europe/Bratislava')->format('Y-m-d H:i P'))->toBe('2026-10-25 02:30 +02:00');
});

// C-64: business hours consume only open time.
it('consumes only open time for business hours', function (): void {
    $calendar = new class implements BusinessCalendar
    {
        public function timezone(): DateTimeZone
        {
            return new DateTimeZone('UTC');
        }

        public function isBusinessDay(CarbonImmutable $date): bool
        {
            return $date->isWeekday();
        }

        public function isOpenAt(CarbonImmutable $instant): bool
        {
            return $instant->isWeekday() && $instant->hour >= 9 && $instant->hour < 17;
        }

        public function nextOpen(CarbonImmutable $instant): ?CarbonImmutable
        {
            if ($this->isOpenAt($instant)) {
                return $instant;
            }

            $day = $instant->hour < 9 ? $instant->setTime(9, 0) : $instant->addDay()->setTime(9, 0);

            while (! $day->isWeekday()) {
                $day = $day->addDay();
            }

            return $day;
        }

        public function nextClose(CarbonImmutable $instant): ?CarbonImmutable
        {
            return $this->isOpenAt($instant) ? $instant->setTime(17, 0) : null;
        }
    };

    $clock = new BusinessClock($calendar);

    // Fri 16:00 + 2h → Mon 10:00 (1h on Friday, the night and weekend skipped).
    expect($clock->addBusinessTime(CarbonImmutable::parse('2026-11-06 16:00', 'UTC'), 7200)->format('D H:i'))->toBe('Mon 10:00')
        // Exactly at the close counts as closed: Mon 17:00 + 30m → Tue 09:30.
        ->and($clock->addBusinessTime(CarbonImmutable::parse('2026-11-09 17:00', 'UTC'), 1800)->format('D H:i'))->toBe('Tue 09:30')
        // Within one open day.
        ->and($clock->addBusinessTime(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), 3600)->format('D H:i'))->toBe('Mon 10:00')
        ->and($clock->addBusinessTime(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), 0)->format('D H:i'))->toBe('Mon 09:00');

    // The built-in weekdays calendar is open all day.
    expect(weekdays('UTC')->addBusinessTime(CarbonImmutable::parse('2026-11-06 23:00', 'UTC'), 7200)->format('D H:i'))->toBe('Mon 01:00');
});

// C-65: a calendar that is never open fails rather than searching forever.
it('refuses a never-open calendar', function (string $method, int $amount): void {
    $never = new BusinessClock(new WeekdayCalendar('UTC', []));

    $never->{$method}(CarbonImmutable::parse('2026-11-09 09:00', 'UTC'), $amount);
})->with([
    ['addBusinessDays', 1],
    ['addBusinessTime', 3600],
])->throws(BusinessCalendarNeverOpenException::class);

it('answers the weekday calendar questions', function (): void {
    $calendar = new WeekdayCalendar('UTC');

    expect($calendar->timezone()->getName())->toBe('UTC')
        ->and($calendar->nextOpen(CarbonImmutable::parse('2026-11-07 10:00', 'UTC'))?->format('D H:i'))->toBe('Mon 00:00')
        ->and($calendar->nextOpen(CarbonImmutable::parse('2026-11-09 10:00', 'UTC'))?->format('D H:i'))->toBe('Mon 10:00')
        ->and($calendar->nextClose(CarbonImmutable::parse('2026-11-09 10:00', 'UTC'))?->format('D H:i'))->toBe('Tue 00:00')
        ->and($calendar->nextClose(CarbonImmutable::parse('2026-11-07 10:00', 'UTC')))->toBeNull()
        ->and((new WeekdayCalendar('UTC', []))->nextOpen(CarbonImmutable::parse('2026-11-07 10:00', 'UTC')))->toBeNull();
});
