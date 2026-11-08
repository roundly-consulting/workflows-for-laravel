<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * An edge kept between the same two nodes whose outcome label changed.
 */
final readonly class EdgeChange
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $before,
        public ?string $after,
    ) {}

    /**
     * @return array{from: string, to: string, before: ?string, after: ?string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'before' => $this->before, 'after' => $this->after];
    }
}
