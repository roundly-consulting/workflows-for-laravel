<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * How many live instances of a version sit at one node.
 */
final readonly class NodeOverlay
{
    public function __construct(
        public string $node,
        public int $running = 0,
        public int $waiting = 0,
        public int $failed = 0,
    ) {}

    /**
     * @return array{node: string, running: int, waiting: int, failed: int}
     */
    public function toArray(): array
    {
        return ['node' => $this->node, 'running' => $this->running, 'waiting' => $this->waiting, 'failed' => $this->failed];
    }
}
