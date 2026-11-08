<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The subject is not of the definition's subject type.
 */
final class SubjectTypeMismatchException extends WorkflowsException
{
    public static function expected(string $expected, string $given): self
    {
        return self::make('subject_type_mismatch', 422, ['expected' => $expected, 'given' => $given]);
    }
}
