<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\OpeningHours;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateTimeZone;
use RoundlyConsulting\OpeningHours\Exceptions\OpenTimeNotFoundException;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Contracts\CountsBusinessTime;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;
use RoundlyConsulting\Workflows\Support\Clock;

/**
 * An opening-hours calendar as business time. Its holidays and exceptions close whole days, so
 * "2 business days" skips them. The arithmetic is opening-hours' own (`addOpenDays()`,
 * `addOpenTime()`), bounded by `opening-hours.search_days`; running out of open time there is
 * `calendar_never_open`.
 *
 * @internal
 */
final readonly class OpeningHoursCalendar implements BusinessCalendar, CountsBusinessTime
{
    public function __construct(
        private OpeningHours $hours,
    ) {}

    public function timezone(): DateTimeZone
    {
        return $this->hours->timezone();
    }

    public function isBusinessDay(CarbonImmutable $date): bool
    {
        return $this->hours->isOpenOnDate($date->setTimezone($this->hours->timezone()));
    }

    public function isOpenAt(CarbonImmutable $instant): bool
    {
        return $this->hours->isOpenAt($instant);
    }

    public function nextOpen(CarbonImmutable $instant): ?CarbonImmutable
    {
        return $this->isOpenAt($instant) ? $instant : $this->hours->nextOpen($instant);
    }

    public function nextClose(CarbonImmutable $instant): ?CarbonImmutable
    {
        return $this->hours->nextClose($instant);
    }

    public function addBusinessDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        try {
            return Clock::utc($this->hours->addOpenDays($from, $days));
        } catch (OpenTimeNotFoundException $exception) {
            throw BusinessCalendarNeverOpenException::within($exception->searchDays);
        }
    }

    public function addBusinessTime(CarbonImmutable $from, int $seconds): CarbonImmutable
    {
        try {
            return Clock::utc($this->hours->addOpenTime($from, CarbonInterval::seconds($seconds)));
        } catch (OpenTimeNotFoundException $exception) {
            throw BusinessCalendarNeverOpenException::within($exception->searchDays);
        }
    }
}
