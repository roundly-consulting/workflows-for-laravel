<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

/**
 * A graph with fresh node ids, and the old id → new id map.
 *
 * @internal
 */
final readonly class RemappedGraph
{
    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, string>  $map
     */
    public function __construct(
        public array $graph,
        public array $map,
    ) {}
}
