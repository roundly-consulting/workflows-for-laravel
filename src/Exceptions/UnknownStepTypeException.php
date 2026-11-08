<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\Support\Suggestions;

/**
 * No step type with that key is registered: the message suggests the closest registered key, or
 * names the package a bridged type comes with.
 */
final class UnknownStepTypeException extends WorkflowsException
{
    /**
     * @param  iterable<string>  $known  the registered keys
     * @param  string|null  $package  the package a bridged type needs
     */
    public static function key(string $key, iterable $known = [], ?string $package = null): self
    {
        if ($package !== null) {
            return self::make('unknown_step_type', 422, ['key' => $key, 'package' => $package], 'unknown_step_type_package');
        }

        $suggestion = Suggestions::closest($key, $known);

        return $suggestion === null
            ? self::make('unknown_step_type', 422, ['key' => $key])
            : self::make('unknown_step_type', 422, ['key' => $key, 'suggestion' => $suggestion], 'unknown_step_type_suggest');
    }
}
