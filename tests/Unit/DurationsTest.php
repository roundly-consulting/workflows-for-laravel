<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Enums\DurationUnit;
use RoundlyConsulting\Workflows\Support\Durations;

it('parses ISO and structured durations', function (): void {
    expect(Durations::parse('P2D')?->iso)->toBe('P2D')
        ->and(Durations::parse(['amount' => 2, 'unit' => 'business_days'])?->unit)->toBe(DurationUnit::BusinessDays)
        ->and(Durations::parse(['amount' => 2, 'unit' => 'business_days', 'calendar' => 'support'])?->calendar)->toBe('support')
        ->and(Durations::parse('2 days'))->toBeNull()
        ->and(Durations::parse('P'))->toBeNull()
        ->and(Durations::parse(['amount' => '2', 'unit' => 'days']))->toBeNull()
        ->and(Durations::parse(['amount' => 2, 'unit' => 'fortnights']))->toBeNull()
        ->and(Durations::parse(['amount' => 2, 'unit' => 'days', 'calendar' => '']))->toBeNull()
        ->and(Durations::parse(42))->toBeNull()
        ->and(Durations::parse('-P1D')?->isNegative())->toBeTrue();
});

// C-50: calendar months clamp to the month end.
it('clamps calendar months to the month end', function (): void {
    $from = CarbonImmutable::parse('2026-01-31 10:00', 'UTC');

    expect(Durations::add(Durations::parse('P1M') ?? throw new LogicException, $from, 'UTC')->format('Y-m-d'))->toBe('2026-02-28')
        ->and(Durations::add(Durations::parse(['amount' => 1, 'unit' => 'months']) ?? throw new LogicException, $from, 'UTC')->format('Y-m-d'))->toBe('2026-02-28')
        ->and(Durations::add(Durations::parse('P1M') ?? throw new LogicException, CarbonImmutable::parse('2028-01-31', 'UTC'), 'UTC')->format('Y-m-d'))->toBe('2028-02-29');
});

it('adds every calendar unit in the version timezone', function (string $unit, int $amount, string $expected): void {
    $from = CarbonImmutable::parse('2026-03-28 10:00', 'Europe/Bratislava')->utc();
    $result = Durations::add(Durations::parse(['amount' => $amount, 'unit' => $unit]) ?? throw new LogicException, $from, 'Europe/Bratislava');

    expect($result->setTimezone('Europe/Bratislava')->format('Y-m-d H:i'))->toBe($expected);
})->with([
    ['minutes', 90, '2026-03-28 11:30'],
    ['hours', 2, '2026-03-28 12:00'],
    // Across the spring-forward night: still 10:00 local the next day.
    ['days', 1, '2026-03-29 10:00'],
    ['weeks', 1, '2026-04-04 10:00'],
]);

it('subtracts a negative ISO offset', function (): void {
    $result = Durations::add(Durations::parse('-P1D') ?? throw new LogicException, CarbonImmutable::parse('2026-11-10 09:00', 'UTC'), 'UTC');

    expect($result->format('Y-m-d H:i'))->toBe('2026-11-09 09:00');
});
