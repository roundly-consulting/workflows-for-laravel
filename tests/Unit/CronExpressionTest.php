<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Support\Schedule\CronExpression;
use RoundlyConsulting\Workflows\Support\Schedule\InvalidCronException;
use RoundlyConsulting\Workflows\Support\Schedule\Presets;

// C-104: every supported token, the refused ones, the day-of-month / day-of-week OR rule, nextAfter.
it('matches the documented subset', function (string $expression, string $at, bool $matches): void {
    expect(CronExpression::parse($expression)->matches(CarbonImmutable::parse($at)))->toBe($matches);
})->with([
    ['* * * * *', '2026-11-09 10:17', true],
    ['17 * * * *', '2026-11-09 10:17', true],
    ['18 * * * *', '2026-11-09 10:17', false],
    ['*/5 * * * *', '2026-11-09 10:15', true],
    ['*/5 * * * *', '2026-11-09 10:16', false],
    ['10-20 * * * *', '2026-11-09 10:15', true],
    ['10-20 * * * *', '2026-11-09 10:21', false],
    ['10-20/5 * * * *', '2026-11-09 10:15', true],
    ['10-20/5 * * * *', '2026-11-09 10:16', false],
    ['0,30 * * * *', '2026-11-09 10:30', true],
    ['0,30 * * * *', '2026-11-09 10:31', false],
    ['0 9 * * *', '2026-11-09 09:00', true],
    ['0 9 * * *', '2026-11-09 10:00', false],
    ['0 9-17 * * *', '2026-11-09 17:00', true],
    ['0 9-17 * * *', '2026-11-09 18:00', false],
    ['0 9 1 * *', '2026-12-01 09:00', true],
    ['0 9 1 * *', '2026-12-02 09:00', false],
    ['0 9 * 12 *', '2026-12-05 09:00', true],
    ['0 9 * 11 *', '2026-12-05 09:00', false],
    ['0 9 * DEC *', '2026-12-05 09:00', true],
    ['0 9 * jan-mar *', '2026-02-05 09:00', true],
    ['0 9 * jan-mar *', '2026-04-05 09:00', false],
    ['0 9 * * MON', '2026-11-09 09:00', true],
    ['0 9 * * mon', '2026-11-10 09:00', false],
    ['0 9 * * 1-5', '2026-11-13 09:00', true],
    ['0 9 * * 1-5', '2026-11-14 09:00', false],
    ['0 9 * * 0', '2026-11-15 09:00', true],
    ['0 9 * * 7', '2026-11-15 09:00', true],
    ['0 9 * * SUN', '2026-11-15 09:00', true],
    ['0 9 * * SAT,SUN', '2026-11-14 09:00', true],
    ['0 9 * * MON-FRI', '2026-11-14 09:00', false],
    ['@hourly', '2026-11-09 10:00', true],
    ['@hourly', '2026-11-09 10:01', false],
    ['@daily', '2026-11-09 00:00', true],
    ['@daily', '2026-11-09 01:00', false],
    ['@weekly', '2026-11-15 00:00', true],
    ['@weekly', '2026-11-14 00:00', false],
    ['@monthly', '2026-12-01 00:00', true],
    ['@yearly', '2027-01-01 00:00', true],
    ['@yearly', '2027-02-01 00:00', false],
    // Vixie: both day fields restricted → either may match.
    ['0 9 1 * MON', '2026-11-09 09:00', true],
    ['0 9 1 * MON', '2026-12-01 09:00', true],
    ['0 9 1 * MON', '2026-12-02 09:00', false],
    ['0 9 1 * *', '2026-11-09 09:00', false],
    ['  0   9 *  *  * ', '2026-11-09 09:00', true],
]);

it('refuses the unsupported tokens naming them', function (string $expression, string $token): void {
    try {
        CronExpression::parse($expression);
        $this->fail('Expected an InvalidCronException.');
    } catch (InvalidCronException $exception) {
        expect($exception->token)->toBe($token);
    }
})->with([
    ['0 9 L * *', 'L'],
    ['0 9 15W * *', '15W'],
    ['0 9 * * 1#2', '1#2'],
    ['0 9 ? * *', '?'],
    ['0 0 9 * * *', '0 0 9 * * *'],
    ['0 9 * *', '0 9 * *'],
    ['60 * * * *', '60'],
    ['* 24 * * *', '24'],
    ['* * 0 * *', '0'],
    ['* * * 13 *', '13'],
    ['* * * * 8', '8'],
    ['*/0 * * * *', '*/0'],
    ['20-10 * * * *', '20-10'],
    ['@reboot', '@reboot'],
    ['a * * * *', 'a'],
    ['1,,2 * * * *', '1,,2'],
    ['* * * FOO *', 'FOO'],
    ['* * * * MON#2', 'MON#2'],
]);

it('reports validity without throwing', function (): void {
    expect(CronExpression::isValid('0 9 * * 1-5'))->toBeTrue()
        ->and(CronExpression::isValid('0 9 L * *'))->toBeFalse();
});

it('walks to the next match without scanning minute by minute', function (string $expression, string $after, ?string $next): void {
    $found = CronExpression::parse($expression)->nextAfter(CarbonImmutable::parse($after), 400);

    expect($found?->format('Y-m-d H:i'))->toBe($next);
})->with([
    ['0 9 * * *', '2026-11-09 09:00', '2026-11-10 09:00'],
    ['0 9 * * *', '2026-11-09 08:59', '2026-11-09 09:00'],
    ['*/15 * * * *', '2026-11-09 10:01', '2026-11-09 10:15'],
    ['30 * * * *', '2026-11-09 10:45', '2026-11-09 11:30'],
    ['0 0 1 * *', '2026-11-15 12:00', '2026-12-01 00:00'],
    ['0 9 * * MON', '2026-11-10 10:00', '2026-11-16 09:00'],
    ['0 9 29 2 *', '2026-03-01 00:00', null],
    ['0 9 31 12 *', '2026-01-01 00:00', '2026-12-31 09:00'],
    ['59 23 31 12 *', '2026-12-31 23:58', '2026-12-31 23:59'],
]);

it('finds the latest match before an instant and after a floor', function (): void {
    $cron = CronExpression::parse('*/10 * * * *');

    expect($cron->previousBefore(CarbonImmutable::parse('2026-11-09 10:25'), CarbonImmutable::parse('2026-11-09 09:55'))?->format('H:i'))->toBe('10:20')
        ->and($cron->previousBefore(CarbonImmutable::parse('2026-11-09 10:05'), CarbonImmutable::parse('2026-11-09 10:01')))->toBeNull();
});

it('compiles presets to cron', function (array $preset, string $cron): void {
    expect(Presets::compile($preset))->toBe($cron);
})->with([
    [['every' => 'day', 'at' => '09:30'], '30 9 * * *'],
    [['every' => 'weekday', 'at' => '08:00'], '0 8 * * 1-5'],
    [['every' => 'week', 'at' => '08:00', 'day' => 3], '0 8 * * 3'],
    [['every' => 'week', 'at' => '08:00'], '0 8 * * 1'],
    [['every' => 'month', 'at' => '07:15', 'day' => 15], '15 7 15 * *'],
    [['every' => 'month'], '0 0 1 * *'],
    [['every' => 'hour', 'at' => '00:20'], '20 * * * *'],
    [['every' => 'minutes', 'interval' => 10], '*/10 * * * *'],
]);

it('refuses an invalid preset', function (array $preset): void {
    Presets::compile($preset);
})->with([
    [['every' => 'fortnight']],
    [['every' => 'day', 'at' => '25:00']],
    [['every' => 'minutes']],
])->throws(InvalidCronException::class);
