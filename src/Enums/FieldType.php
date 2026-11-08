<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The type of a registered subject field.
 */
#[TranslatedLabels('workflows::enums.field_type')]
enum FieldType: string
{
    use Helpers;

    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
    case Enum = 'enum';
    case Party = 'party';
    case List = 'list';
}
