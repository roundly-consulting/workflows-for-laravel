<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * A version: the editable draft, a published (immutable) version, or one a newer publish superseded.
 */
#[TranslatedLabels('workflows::enums.version_status')]
enum VersionStatus: string
{
    use Helpers;

    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
