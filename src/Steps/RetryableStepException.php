<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use Carbon\CarbonInterface;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RuntimeException;

/**
 * Thrown by a handler for a transient failure: the attempt is retried under the step's retry
 * policy (not before `retryAfter`, when given), with the same idempotency key.
 */
final class RetryableStepException extends RuntimeException
{
    public function __construct(
        private readonly string $failureCode,
        string $message,
        private readonly ?CarbonInterface $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function because(FailureCode|string $code, string $message, ?CarbonInterface $retryAfter = null): self
    {
        return new self($code instanceof FailureCode ? $code->value : $code, $message, $retryAfter);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    public function retryAfter(): ?CarbonInterface
    {
        return $this->retryAfter;
    }
}
