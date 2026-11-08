<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * How often a side-effect step is attempted and how long the engine waits between attempts.
 * `backoff` is in seconds; its last value repeats for later attempts.
 */
final readonly class RetryPolicy
{
    /**
     * @param  list<int>  $backoff
     */
    public function __construct(
        public int $maxAttempts,
        public array $backoff = [30, 120, 600],
        public float $jitter = 0.0,
    ) {}

    /**
     * The policy a graph `retry` block describes, over a base policy (keys it omits keep the
     * base's values). Returns null for anything that is not a retry block.
     */
    public static function overlay(mixed $block, self $base, int $cap): ?self
    {
        if (! is_array($block)) {
            return null;
        }

        $attempts = $block['max_attempts'] ?? $base->maxAttempts;
        $backoff = $block['backoff'] ?? $base->backoff;

        if (! is_int($attempts) || $attempts < 1 || ! is_array($backoff)) {
            return null;
        }

        $seconds = [];

        foreach ($backoff as $value) {
            if (! is_int($value) || $value < 0) {
                return null;
            }

            $seconds[] = $value;
        }

        return new self(min($attempts, $cap), $seconds === [] ? $base->backoff : $seconds, $base->jitter);
    }

    /**
     * Seconds to wait before the given attempt (2 = the first retry), jitter excluded.
     */
    public function delayBefore(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        $index = max(0, $attempt - 2);

        return $this->backoff[min($index, count($this->backoff) - 1)];
    }

    public function withCap(int $cap): self
    {
        return new self(min($this->maxAttempts, $cap), $this->backoff, $this->jitter);
    }
}
