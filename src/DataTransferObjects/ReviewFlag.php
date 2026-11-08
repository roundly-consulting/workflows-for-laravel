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
     * A flag as stored in a version's `review_flags` json column; null for a malformed entry.
     */
    public static function fromArray(mixed $flag): ?self
    {
        if (! is_array($flag) || ! is_string($flag['node'] ?? null) || ! is_string($flag['path'] ?? null)) {
            return null;
        }

        return new self($flag['node'], $flag['path'], is_string($flag['reason'] ?? null) ? $flag['reason'] : 'owner_specific');
    }

    public function is(string $node, string $path): bool
    {
        return $this->node === $node && $this->path === $path;
    }

    /**
     * @return array{node: string, path: string, reason: string}
     */
    public function toArray(): array
    {
        return ['node' => $this->node, 'path' => $this->path, 'reason' => $this->reason];
    }
}
