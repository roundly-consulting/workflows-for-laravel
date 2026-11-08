<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schedule;

use InvalidArgumentException;

/**
 * A cron expression outside the supported subset, naming the offending token.
 *
 * @internal
 */
final class InvalidCronException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $token,
    ) {
        parent::__construct(sprintf('Unsupported cron token [%s].', $token));
    }
}
