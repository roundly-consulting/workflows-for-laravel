<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How serious a `workflows:doctor` finding is: `fail` exits 1, `warn` exits 1 under `--strict`.
 */
#[TranslatedLabels('workflows::enums.doctor_level')]
enum DoctorLevel: string
{
    use Helpers;

    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
}
