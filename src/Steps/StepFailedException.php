<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use RoundlyConsulting\Workflows\Enums\FailureCode;
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

    /**
     * A FailureCode case, or the host step's own code.
     */
    public static function because(FailureCode|string $code, string $message): static
    {
        return new static($code instanceof FailureCode ? $code->value : $code, $message);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
