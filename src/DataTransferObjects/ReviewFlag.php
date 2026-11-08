<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * An owner-specific value a template import cleared: the node (or `*` for settings), the JSON
 * pointer and why. Publish is blocked until it is set or dismissed.
 */
final readonly class ReviewFlag
{
    public function __construct(
        public string $node,
        public string $path,
        public string $reason,
    ) {}

    /**
     * @return array{node: string, path: string, reason: string}
     */
    public function toArray(): array
    {
        return ['node' => $this->node, 'path' => $this->path, 'reason' => $this->reason];
    }
}
