<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\Support\Messages;

/**
 * A dry run or export asked for a version the definition does not have (or it has none yet).
 */
final class NoVersionException extends WorkflowsException
{
    public static function key(string $key, ?int $number = null): self
    {
        return $number === null
            ? new self(Messages::get('exceptions.no_version_yet', ['key' => $key]), 'no_version', 404)
            : new self(Messages::get('exceptions.no_version', ['key' => $key, 'number' => $number]), 'no_version', 404);
    }
}
