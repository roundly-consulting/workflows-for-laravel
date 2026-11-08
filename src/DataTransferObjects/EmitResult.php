<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * What an emit did: one start per matching definition (skipped ones carry the reason), the
 * definitions that refused to start (one failing definition never stops the others), and how many
 * waiting steps it resumed.
 */
final readonly class EmitResult
{
    /**
     * @param  list<StartResult>  $started
     * @param  list<EmitFailure>  $failed
     */
    public function __construct(
        public array $started = [],
        public int $resumed = 0,
        public array $failed = [],
    ) {}

    /**
     * @return list<StartResult>
     */
    public function skipped(): array
    {
        return array_values(array_filter($this->started, static fn (StartResult $result): bool => $result->wasSkipped()));
    }
}
