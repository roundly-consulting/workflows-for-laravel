<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use RuntimeException;

/**
 * Thrown by a handler to fail its step for good: the attempt is never retried, and the instance
 * fails with the code and the (author-written) message.
 */
class StepFailedException extends RuntimeException
{
    final public function __construct(
        private readonly string $failureCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(string $code, string $message): static
    {
        return new static($code, $message);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
