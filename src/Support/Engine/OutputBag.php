<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

/**
 * The outputs of the nodes a dry run walked, by node id.
 *
 * @internal
 */
final class OutputBag
{
    /** @var array<string, array<string, mixed>> */
    private array $outputs = [];

    /**
     * @param  array<string, mixed>  $output
     */
    public function set(string $node, array $output): void
    {
        $this->outputs[$node] = $output;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $node): ?array
    {
        return $this->outputs[$node] ?? null;
    }
}
