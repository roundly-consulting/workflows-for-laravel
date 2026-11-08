<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * An edge as a builder highlights it: its endpoints and outcome label.
 */
final readonly class EdgeReference
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $outcome = null,
    ) {}

    /**
     * @return array{from: string, to: string, outcome: ?string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'outcome' => $this->outcome];
    }
}
