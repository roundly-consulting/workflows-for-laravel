<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Calendars;

use Carbon\CarbonImmutable;
use DateTimeZone;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;

/**
 * The built-in `weekdays` calendar: every configured weekday is open all day, in the version's
 * timezone. No holidays (use an opening-hours calendar for those).
 */
final readonly class WeekdayCalendar implements BusinessCalendar
{
    /**
     * @param  list<int>  $weekdays  ISO weekday numbers (1 = Monday)
     */
    public function __construct(
        private string $timezone,
        private array $weekdays = [1, 2, 3, 4, 5],
        private int $searchDays = 366,
    ) {}

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    public function isBusinessDay(CarbonImmutable $date): bool
    {
        return in_array($date->setTimezone($this->timezone)->dayOfWeekIso, $this->weekdays, true);
    }

    public function isOpenAt(CarbonImmutable $instant): bool
    {
        return $this->isBusinessDay($instant);
    }

    public function nextOpen(CarbonImmutable $instant): ?CarbonImmutable
    {
        if ($this->isOpenAt($instant)) {
            return $instant;
        }

        $day = $instant->setTimezone($this->timezone)->startOfDay();

        for ($i = 0; $i < $this->searchDays; $i++) {
            $day = $day->addDay();

            if ($this->isBusinessDay($day)) {
                return $day->utc();
            }
        }

        return null;
    }

    public function nextClose(CarbonImmutable $instant): ?CarbonImmutable
    {
        if (! $this->isOpenAt($instant)) {
            return null;
        }

        return $instant->setTimezone($this->timezone)->startOfDay()->addDay()->utc();
    }
}
