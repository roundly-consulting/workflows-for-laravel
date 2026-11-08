<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * What an emit did: one start per matching definition (skipped ones carry the reason), and how
 * many waiting steps it resumed.
 */
final readonly class EmitResult
{
    /**
     * @param  list<StartResult>  $started
     */
    public function __construct(
        public array $started = [],
        public int $resumed = 0,
    ) {}

    /**
     * @return list<StartResult>
     */
    public function skipped(): array
    {
        return array_values(array_filter($this->started, static fn (StartResult $result): bool => $result->wasSkipped()));
    }
}
