<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;

/**
 * A graph after the owner-specific scan: the graph (with the values cleared, on import) and a
 * review flag per owner-specific value.
 *
 * @internal
 */
final readonly class ClearedGraph
{
    /**
     * @param  array<string, mixed>  $graph
     * @param  list<ReviewFlag>  $flags
     */
    public function __construct(
        public array $graph,
        public array $flags,
    ) {}
}
