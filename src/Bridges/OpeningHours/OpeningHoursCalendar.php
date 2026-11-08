<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\OpeningHours;

use Carbon\CarbonImmutable;
use DateTimeZone;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;

/**
 * An opening-hours calendar as business time. Its holidays and exceptions close whole days, so
 * "2 business days" skips them.
 *
 * @internal
 */
final readonly class OpeningHoursCalendar implements BusinessCalendar
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
}
