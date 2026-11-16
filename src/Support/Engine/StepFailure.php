<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Enums\FailureCode;

/**
 * A failed attempt: its code and message, whether it may be retried (and not before when),
 * whether the message is author-written (rendered) or an unknown exception's (stored only), and
 * the step's structured details — already normalised (FailureDetails) where the author gave them.
 *
 * @internal
 */
final readonly class StepFailure
{
    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable,
        public ?CarbonImmutable $retryAfter = null,
        public ?array $details = null,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public static function permanent(FailureCode|string $code, string $message, ?array $details = null): self
    {
        return new self($code instanceof FailureCode ? $code->value : $code, $message, false, null, $details);
    }

    /**
     * @param  array<array-key, mixed>|null  $details
     */
    public static function transient(FailureCode|string $code, string $message, ?CarbonImmutable $retryAfter = null, ?array $details = null): self
    {
        return new self($code instanceof FailureCode ? $code->value : $code, $message, true, $retryAfter, $details);
    }
}
