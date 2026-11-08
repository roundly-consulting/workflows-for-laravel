<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * One escalation level of one step visit.
 */
#[TranslatedLabels('workflows::enums.escalation_status')]
enum EscalationStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Claimed = 'claimed';
    case Fired = 'fired';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
