<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The subject type key (or the model) is not registered.
 */
final class UnknownSubjectTypeException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('unknown_subject_type', 422, ['key' => $key]);
    }

    public static function model(string $basename): self
    {
        return self::make('unregistered_subject_model', 422, ['model' => $basename]);
    }
}
