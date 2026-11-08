<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Workflows\DataTransferObjects\Duration;
use RoundlyConsulting\Workflows\Support\Clock;

/**
 * What a waiting step waits on. The engine persists it as wait rows (a timer, an event key or a
 * condition poll, plus an optional timeout and safety-net timers) and wakes the step on them.
 */
final readonly class WaitSpec
{
    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>|null  $condition
     * @param  list<CarbonImmutable>  $timers
     */
    public function __construct(
        public ?CarbonImmutable $until = null,
        public ?string $event = null,
        public array $match = [],
        public ?array $condition = null,
        public ?Duration $poll = null,
        public ?Duration $timeout = null,
        public ?CarbonImmutable $timeoutAt = null,
        public string $timeoutOutcome = 'timed_out',
        public bool $probe = false,
        public array $timers = [],
    ) {}

    public static function until(CarbonInterface $at): self
    {
        return new self(until: Clock::utc($at));
    }

    /**
     * @param  array<string, mixed>  $match
     */
    public static function event(string $key, array $match = []): self
    {
        return new self(event: $key, match: $match);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public static function condition(array $condition, ?Duration $poll = null): self
    {
        return new self(condition: $condition, poll: $poll);
    }

    public function timeout(Duration|CarbonInterface $after, string $outcome = 'timed_out'): self
    {
        return new self(
            $this->until, $this->event, $this->match, $this->condition, $this->poll,
            $after instanceof Duration ? $after : null,
            $after instanceof CarbonInterface ? Clock::utc($after) : null,
            $outcome, $this->probe, $this->timers,
        );
    }

    /**
     * Resume once right after the wait is recorded, so a source of truth that settled before the
     * wait rows existed (an approval resolved inside open()) is never missed.
     */
    public function probe(): self
    {
        return new self($this->until, $this->event, $this->match, $this->condition, $this->poll, $this->timeout, $this->timeoutAt, $this->timeoutOutcome, true, $this->timers);
    }

    /**
     * An extra timer that resumes the step with a timer signal (a lost-event safety net).
     */
    public function alsoAt(CarbonInterface $at): self
    {
        return new self($this->until, $this->event, $this->match, $this->condition, $this->poll, $this->timeout, $this->timeoutAt, $this->timeoutOutcome, $this->probe, [...$this->timers, Clock::utc($at)]);
    }
}
