<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\Support\Suggestions;

/**
 * The subject type key (or the model) is not registered; the message suggests the closest
 * registered key.
 */
final class UnknownSubjectTypeException extends WorkflowsException
{
    /**
     * @param  iterable<string>  $known  the registered keys
     */
    public static function key(string $key, iterable $known = []): self
    {
        $suggestion = Suggestions::closest($key, $known);

        return $suggestion === null
            ? self::make('unknown_subject_type', 422, ['key' => $key])
            : self::make('unknown_subject_type', 422, ['key' => $key, 'suggestion' => $suggestion], 'unknown_subject_type_suggest');
    }

    public static function model(string $basename): self
    {
        return self::make('unregistered_subject_model', 422, ['model' => $basename]);
    }
}
