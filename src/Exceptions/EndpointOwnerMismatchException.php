<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * An inbound webhook endpoint belongs to another owner than the workflow whose trigger names it
 * (or its owner no longer exists): the delivery starts nothing. Rendered as a 404 so it never
 * confirms that another owner's workflow exists.
 */
final class EndpointOwnerMismatchException extends WorkflowsException
{
    public static function endpoint(): self
    {
        return self::make('owner_mismatch', 404, line: 'endpoint_owner_mismatch');
    }
}
