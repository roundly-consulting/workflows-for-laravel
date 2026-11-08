<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * The run overlay of a version: per node, how many instances are running, waiting or failed
 * there (one grouped query).
 */
final readonly class OverlayReport
{
    /**
     * @param  list<NodeOverlay>  $nodes
     */
    public function __construct(
        public int $version,
        public array $nodes,
        public int $total,
    ) {}

    public function node(string $id): ?NodeOverlay
    {
        foreach ($this->nodes as $node) {
            if ($node->node === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array{version: int, total: int, nodes: list<array{node: string, running: int, waiting: int, failed: int}>}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'total' => $this->total,
            'nodes' => array_map(static fn (NodeOverlay $node): array => $node->toArray(), $this->nodes),
        ];
    }
}
