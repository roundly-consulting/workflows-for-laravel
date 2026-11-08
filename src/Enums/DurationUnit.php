<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The unit of a structured duration.
 */
#[TranslatedLabels('workflows::enums.duration_unit')]
enum DurationUnit: string
{
    use Helpers;

    case Minutes = 'minutes';
    case Hours = 'hours';
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
    case BusinessMinutes = 'business_minutes';
    case BusinessHours = 'business_hours';
    case BusinessDays = 'business_days';

    public function isBusiness(): bool
    {
        return in_array($this, [self::BusinessMinutes, self::BusinessHours, self::BusinessDays], true);
    }
}
