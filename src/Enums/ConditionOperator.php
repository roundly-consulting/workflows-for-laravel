<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The operators of a declarative condition.
 */
#[TranslatedLabels('workflows::enums.condition_operator')]
enum ConditionOperator: string
{
    use Helpers;

    case Equals = 'equals';
    case In = 'in';
    case Gt = 'gt';
    case Lt = 'lt';
    case Between = 'between';
    case IsEmpty = 'empty';
    case Contains = 'contains';
    case Before = 'before';
    case After = 'after';
}
