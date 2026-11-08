<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

/**
 * A parsed `roundly.workflows.graph/v1` document: settings, nodes by id, edges.
 */
final readonly class Graph
{
    public const string SCHEMA = 'roundly.workflows.graph/v1';

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, Node>  $nodes
     * @param  list<Edge>  $edges
     */
    public function __construct(
        public string $schema,
        public array $settings,
        public array $nodes,
        public array $edges,
    ) {}

    public function node(string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * @return list<Node>
     */
    public function nodesOfType(string $type): array
    {
        return array_values(array_filter($this->nodes, static fn (Node $node): bool => $node->type === $type));
    }

    public function trigger(): ?Node
    {
        return $this->nodesOfType('trigger')[0] ?? null;
    }

    /**
     * @return list<Edge>
     */
    public function outgoing(string $id): array
    {
        return array_values(array_filter($this->edges, static fn (Edge $edge): bool => $edge->from === $id));
    }

    /**
     * @return list<Edge>
     */
    public function incoming(string $id): array
    {
        return array_values(array_filter($this->edges, static fn (Edge $edge): bool => $edge->to === $id));
    }

    /**
     * The edge an outcome takes: the labelled one, else an unlabelled edge of a single-outcome
     * node (`next`).
     */
    public function edgeFor(string $from, string $outcome): ?Edge
    {
        $unlabelled = null;

        foreach ($this->edges as $edge) {
            if ($edge->from !== $from) {
                continue;
            }

            if ($edge->outcome === $outcome) {
                return $edge;
            }

            if ($edge->outcome === null) {
                $unlabelled = $edge;
            }
        }

        return $outcome === 'next' ? $unlabelled : null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'settings' => $this->settings,
            'nodes' => array_values(array_map(static fn (Node $node): array => $node->toArray(), $this->nodes)),
            'edges' => array_map(static fn (Edge $edge): array => $edge->toArray(), $this->edges),
        ];
    }
}
