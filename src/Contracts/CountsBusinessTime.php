<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;

/**
 * Business-time arithmetic a calendar does itself — the opening-hours bridge hands it to
 * opening-hours. A calendar without it is counted by the package's own clock, walking
 * `BusinessCalendar`'s open intervals.
 */
interface CountsBusinessTime
{
    /**
     * The same local wall-clock time on the n-th business date strictly after `$from`'s local
     * date (Fri 15:00 + 2 → Tue 15:00); a repeated time takes its first occurrence.
     *
     * @throws BusinessCalendarNeverOpenException
     */
    public function addBusinessDays(CarbonImmutable $from, int $days): CarbonImmutable;

    /**
     * The instant `$seconds` of open time after `$from`, jumping over closed time.
     *
     * @throws BusinessCalendarNeverOpenException
     */
    public function addBusinessTime(CarbonImmutable $from, int $seconds): CarbonImmutable;
}
