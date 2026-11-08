<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

use RoundlyConsulting\Workflows\DataTransferObjects\EdgeReference;

/**
 * A directed link, identified by `(from, outcome)`.
 */
final readonly class Edge
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $outcome = null,
    ) {}

    public function reference(): EdgeReference
    {
        return new EdgeReference($this->from, $this->to, $this->outcome);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $edge = ['from' => $this->from, 'to' => $this->to];

        if ($this->outcome !== null) {
            $edge['outcome'] = $this->outcome;
        }

        return $edge;
    }
}
