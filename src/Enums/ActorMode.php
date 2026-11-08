<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Who a version runs as.
 */
#[TranslatedLabels('workflows::enums.actor_mode')]
enum ActorMode: string
{
    use Helpers;

    case System = 'system';
    case OnBehalfOf = 'on_behalf_of';
}
