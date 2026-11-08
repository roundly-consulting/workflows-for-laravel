<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How a definition starts.
 */
#[TranslatedLabels('workflows::enums.trigger_type')]
enum TriggerType: string
{
    use Helpers;

    case Manual = 'manual';
    case Event = 'event';
    case Schedule = 'schedule';
    case Webhook = 'webhook';
}
