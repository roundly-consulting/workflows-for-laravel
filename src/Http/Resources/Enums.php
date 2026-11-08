<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use BackedEnum;

/**
 * An enum as JSON: its value plus its translated label.
 *
 * @internal
 */
final class Enums
{
    /**
     * @return array{value: string|int, label: string}|null
     */
    public static function render(?BackedEnum $enum): ?array
    {
        if ($enum === null) {
            return null;
        }

        $label = method_exists($enum, 'label') ? $enum->label() : $enum->name;

        return ['value' => $enum->value, 'label' => is_string($label) ? $label : $enum->name];
    }
}
