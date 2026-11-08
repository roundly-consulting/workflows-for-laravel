<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Business time for delays and escalations ("2 business days"). Open intervals are half-open:
 * an instant exactly at a close counts as closed.
 */
interface BusinessCalendar
{
    public function timezone(): DateTimeZone;

    /**
     * Whether the local calendar date of `$date` is a business day.
     */
    public function isBusinessDay(CarbonImmutable $date): bool;

    public function isOpenAt(CarbonImmutable $instant): bool;

    /**
     * The next instant at or after `$instant` that is open (`$instant` itself when open), or null
     * when none follows within the calendar's search window.
     */
    public function nextOpen(CarbonImmutable $instant): ?CarbonImmutable;

    /**
     * The next close strictly after `$instant` while it is open, or null for "never closes".
     */
    public function nextClose(CarbonImmutable $instant): ?CarbonImmutable;
}
