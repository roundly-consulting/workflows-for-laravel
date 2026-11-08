<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Whether a validation finding blocks publish.
 */
#[TranslatedLabels('workflows::enums.severity')]
enum Severity: string
{
    use Helpers;

    case Error = 'error';
    case Warning = 'warning';
}
