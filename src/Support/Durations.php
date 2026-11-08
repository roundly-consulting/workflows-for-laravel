<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Contracts\CountsBusinessTime;
use RoundlyConsulting\Workflows\DataTransferObjects\Duration;
use RoundlyConsulting\Workflows\Enums\DurationUnit;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use Throwable;

/**
 * Graph durations: parsing (ISO 8601 or `{amount, unit, calendar?}`) and adding one to an
 * instant. Calendar units use Carbon in the version timezone without month overflow
 * (`P1M` from Jan 31 is Feb 28/29); business units go through the BusinessClock.
 *
 * @internal
 */
final class Durations
{
    public static function parse(mixed $value): ?Duration
    {
        if (is_string($value)) {
            return self::iso($value) === null ? null : new Duration(iso: $value);
        }

        if (! is_array($value) || ! is_int($value['amount'] ?? null) || ! is_string($value['unit'] ?? null)) {
            return null;
        }

        $unit = DurationUnit::tryFrom($value['unit']);
        $calendar = $value['calendar'] ?? null;

        if ($unit === null || ($calendar !== null && (! is_string($calendar) || $calendar === ''))) {
            return null;
        }

        return new Duration(amount: $value['amount'], unit: $unit, calendar: is_string($calendar) ? $calendar : null);
    }

    /**
     * The interval an ISO string spells (a leading `-` negates it), or null.
     */
    public static function iso(string $value): ?CarbonInterval
    {
        $negative = str_starts_with($value, '-');
        $spec = $negative ? substr($value, 1) : $value;

        if (preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', $spec) !== 1) {
            return null;
        }

        try {
            $interval = new CarbonInterval($spec);
        } catch (Throwable) {
            return null;
        }

        if ($negative) {
            $interval->invert();
        }

        return $interval;
    }

    /**
     * The instant a duration after `$from` lands on.
     *
     * @throws BusinessCalendarNeverOpenException
     * @throws UnknownCalendarException
     */
    public static function add(Duration $duration, CarbonImmutable $from, string $timezone, ?string $defaultCalendar = null, ?Model $owner = null): CarbonImmutable
    {
        if ($duration->iso !== null) {
            $interval = self::iso($duration->iso) ?? CarbonInterval::seconds(0);

            return self::addCalendar($from, $interval, $timezone);
        }

        if (! $duration->unit->isBusiness()) {
            $local = $from->setTimezone($timezone);

            $result = match ($duration->unit) {
                DurationUnit::Minutes => $local->addMinutes($duration->amount),
                DurationUnit::Hours => $local->addHours($duration->amount),
                DurationUnit::Days => $local->addDays($duration->amount),
                DurationUnit::Weeks => $local->addWeeks($duration->amount),
                default => $local->addMonthsNoOverflow($duration->amount),
            };

            return Clock::utc($result);
        }

        $calendar = app(CalendarRegistry::class)->find($duration->calendar ?? $defaultCalendar ?? 'weekdays', $owner, $timezone);
        // A calendar that counts its own business time (opening hours) does; else the package's clock.
        $clock = $calendar instanceof CountsBusinessTime ? $calendar : new BusinessClock($calendar);

        return match ($duration->unit) {
            DurationUnit::BusinessDays => $clock->addBusinessDays($from, $duration->amount),
            DurationUnit::BusinessHours => $clock->addBusinessTime($from, $duration->amount * 3600),
            default => $clock->addBusinessTime($from, $duration->amount * 60),
        };
    }

    private static function addCalendar(CarbonImmutable $from, CarbonInterval $interval, string $timezone): CarbonImmutable
    {
        $sign = $interval->invert === 1 ? -1 : 1;
        $local = $from->setTimezone($timezone)
            ->addYearsNoOverflow($sign * $interval->y)
            ->addMonthsNoOverflow($sign * $interval->m)
            ->addDays($sign * $interval->d)
            ->addHours($sign * $interval->h)
            ->addMinutes($sign * $interval->i)
            ->addSeconds($sign * $interval->s);

        return Clock::utc($local);
    }
}
