<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What resumed a waiting step.
 */
#[TranslatedLabels('workflows::enums.signal_kind')]
enum SignalKind: string
{
    use Helpers;

    case Timer = 'timer';
    case Event = 'event';
    case Condition = 'condition';
    case Probe = 'probe';
    case Retry = 'retry';
    case Timeout = 'timeout';
}
