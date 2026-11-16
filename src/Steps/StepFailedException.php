<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidFailureDetailsException;
use RoundlyConsulting\Workflows\Support\FailureDetails;
use RuntimeException;

/**
 * Thrown by a handler to fail its step for good: the attempt is never retried, and the instance
 * fails with the code, the (author-written) message and, through `withDetails()`, structured
 * details: `throw StepFailedException::because('agent_run_failed', '…')->withDetails([...])`.
 */
class StepFailedException extends RuntimeException
{
    /** @var array<array-key, mixed>|null */
    private ?array $details = null;

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

    /**
     * Attach structured details the read API and the events expose beside the code
     * (`['run_id' => …, 'stop_reason' => …]`), normalised like `StepResult::fail()`'s: secret-looking
     * keys redacted, oversized details truncated to a marker, anything not plain JSON refused.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws InvalidFailureDetailsException
     */
    public function withDetails(array $details): static
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
}
