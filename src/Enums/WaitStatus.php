<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * A wait row: pending until wake claims it, then fired (or cancelled).
 */
#[TranslatedLabels('workflows::enums.wait_status')]
enum WaitStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Claimed = 'claimed';
    case Fired = 'fired';
    case Cancelled = 'cancelled';
}
