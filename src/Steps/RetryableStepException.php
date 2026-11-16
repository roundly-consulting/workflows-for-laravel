<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use Carbon\CarbonInterface;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidFailureDetailsException;
use RoundlyConsulting\Workflows\Support\FailureDetails;
use RuntimeException;

/**
 * Thrown by a handler for a transient failure: the attempt is retried under the step's retry
 * policy (not before `retryAfter`, when given), with the same idempotency key. `withDetails()`
 * attaches structured details to the failed attempt (and to the instance, if it was the last).
 */
final class RetryableStepException extends RuntimeException
{
    /** @var array<array-key, mixed>|null */
    private ?array $details = null;

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

    /**
     * Attach structured details the read API and the events expose beside the code
     * (`['run_id' => …, 'stop_reason' => …]`), normalised like `StepResult::fail()`'s: secret-looking
     * keys redacted, oversized details truncated to a marker, anything not plain JSON refused.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws InvalidFailureDetailsException
     */
    public function withDetails(array $details): self
    {
        $this->details = FailureDetails::normalise($details);

        return $this;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }

    public function retryAfter(): ?CarbonInterface
    {
        return $this->retryAfter;
    }
}
