<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The subject does not belong to the owner. Rendered as a 404 so it never confirms that a
 * record of another owner exists.
 */
final class SubjectOwnerMismatchException extends WorkflowsException
{
    public static function forSubject(): self
    {
        return self::make('subject_owner_mismatch', 404);
    }
}
