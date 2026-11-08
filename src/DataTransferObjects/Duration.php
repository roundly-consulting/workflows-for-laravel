<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\DurationUnit;

/**
 * A graph duration: an ISO 8601 calendar interval (`P2D`, `PT4H`) or an amount of a unit,
 * business units included, optionally on a named business calendar.
 */
final readonly class Duration
{
    public function __construct(
        public ?string $iso = null,
        public int $amount = 0,
        public DurationUnit $unit = DurationUnit::Minutes,
        public ?string $calendar = null,
    ) {}

    public function isBusiness(): bool
    {
        return $this->iso === null && $this->unit->isBusiness();
    }

    public function isNegative(): bool
    {
        return $this->iso !== null ? str_starts_with($this->iso, '-') : $this->amount < 0;
    }
}
