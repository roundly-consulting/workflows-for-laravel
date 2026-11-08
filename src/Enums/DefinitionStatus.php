<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * A definition: a draft until first published, active while it starts instances, retired once it stops.
 */
#[TranslatedLabels('workflows::enums.definition_status')]
enum DefinitionStatus: string
{
    use Helpers;

    case Draft = 'draft';
    case Active = 'active';
    case Retired = 'retired';
}
