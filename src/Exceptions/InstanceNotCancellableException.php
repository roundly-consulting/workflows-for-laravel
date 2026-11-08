<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The instance already ended.
 */
final class InstanceNotCancellableException extends WorkflowsException
{
    public static function forStatus(string $status): self
    {
        return self::make('instance_not_cancellable', 409, ['status' => $status]);
    }
}
