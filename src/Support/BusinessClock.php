<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;

/**
 * Business-time arithmetic over any BusinessCalendar.
 *
 * - `addBusinessDays()`: the same local wall-clock time on the n-th business date strictly after
 *   `from`'s local date (Fri 15:00 + 2 → Tue 15:00). A time in a DST gap moves to the next valid
 *   instant; a repeated time takes its first occurrence.
 * - `addBusinessTime()`: consumes open seconds only, jumping over closed time.
 *
 * Both are bounded by `business_time.max_search_days`.
 *
 * @internal
 */
final readonly class BusinessClock
{
    public function __construct(
        private BusinessCalendar $calendar,
    ) {}

    public function addBusinessDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        if ($days <= 0) {
            return $from;
        }

        $timezone = $this->calendar->timezone();
        $local = $from->setTimezone($timezone);
        $date = $local->startOfDay();
        $max = WorkflowsConfig::businessMaxSearchDays();
        $found = 0;

        for ($i = 0; $i < $max; $i++) {
            $date = $date->addDay();

            if ($this->calendar->isBusinessDay($date->setTime(12, 0))) {
                $found++;

                if ($found === $days) {
                    $target = CarbonImmutable::create($date->year, $date->month, $date->day, $local->hour, $local->minute, $local->second, $timezone);

                    return Clock::utc($target ?? $date);
                }
            }
        }

        throw BusinessCalendarNeverOpenException::within($max);
    }

    public function addBusinessTime(CarbonImmutable $from, int $seconds): CarbonImmutable
    {
        if ($seconds <= 0) {
            return $from;
        }

        $max = WorkflowsConfig::businessMaxSearchDays();
        $limit = $from->addDays($max);
        $cursor = Clock::utc($from);
        $remaining = $seconds;

        while ($remaining > 0) {
            if ($cursor->greaterThan($limit)) {
                throw BusinessCalendarNeverOpenException::within($max);
            }

            if (! $this->calendar->isOpenAt($cursor)) {
                $next = $this->calendar->nextOpen($cursor);

                if ($next === null || $next->lessThanOrEqualTo($cursor) && ! $this->calendar->isOpenAt($next)) {
                    throw BusinessCalendarNeverOpenException::within($max);
                }

                $cursor = Clock::utc($next);

                continue;
            }

            $close = $this->calendar->nextClose($cursor);

            if ($close === null) {
                return $cursor->addSeconds($remaining);
            }

            $available = max(0, (int) $cursor->diffInSeconds($close, true));

            if ($available === 0) {
                $cursor = $cursor->addSecond();

                continue;
            }

            $chunk = min($remaining, $available);
            $cursor = $cursor->addSeconds($chunk);
            $remaining -= $chunk;
        }

        return $cursor;
    }
}
