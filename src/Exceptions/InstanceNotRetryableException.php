<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * Only a failed instance whose last step run failed can be retried.
 */
final class InstanceNotRetryableException extends WorkflowsException
{
    public static function forStatus(string $status): self
    {
        return self::make('instance_not_retryable', 409, ['status' => $status]);
    }
}
