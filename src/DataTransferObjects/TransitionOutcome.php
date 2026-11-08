<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Carbon\CarbonImmutable;

/**
 * What a transition adapter answered: applied, or denied with the lifecycle's code and message
 * (and whether, and when, a retry may succeed).
 */
final readonly class TransitionOutcome
{
    public function __construct(
        public bool $applied,
        public ?string $deniedCode = null,
        public ?string $deniedMessage = null,
        public ?CarbonImmutable $retryAfter = null,
        public bool $retryable = false,
    ) {}

    public static function applied(): self
    {
        return new self(true);
    }

    public static function denied(string $code, string $message, bool $retryable = false, ?CarbonImmutable $retryAfter = null): self
    {
        return new self(false, $code, $message, $retryAfter, $retryable);
    }
}
