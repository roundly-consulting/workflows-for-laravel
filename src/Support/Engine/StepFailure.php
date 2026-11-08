<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Enums\FailureCode;

/**
 * A failed attempt: its code and message, whether it may be retried (and not before when), and
 * whether the message is author-written (rendered) or an unknown exception's (stored only).
 *
 * @internal
 */
final readonly class StepFailure
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable,
        public ?CarbonImmutable $retryAfter = null,
    ) {}

    public static function permanent(FailureCode|string $code, string $message): self
    {
        return new self($code instanceof FailureCode ? $code->value : $code, $message, false);
    }

    public static function transient(FailureCode|string $code, string $message, ?CarbonImmutable $retryAfter = null): self
    {
        return new self($code instanceof FailureCode ? $code->value : $code, $message, true, $retryAfter);
    }
}
