<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What a persisted wait row wakes on.
 */
#[TranslatedLabels('workflows::enums.wait_kind')]
enum WaitKind: string
{
    use Helpers;

    case Timer = 'timer';
    case Event = 'event';
    case Condition = 'condition';
    case Retry = 'retry';
    case Timeout = 'timeout';
}
