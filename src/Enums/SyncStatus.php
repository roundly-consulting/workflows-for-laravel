<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What `workflows:sync` did with one definition file.
 */
#[TranslatedLabels('workflows::enums.sync_status')]
enum SyncStatus: string
{
    use Helpers;

    case Created = 'created';
    case Drafted = 'drafted';
    case Published = 'published';
    case Unchanged = 'unchanged';
    case Invalid = 'invalid';
    case Failed = 'failed';
}
