<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

use RoundlyConsulting\Workflows\DataTransferObjects\EdgeReference;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Reads a graph payload: the structural pass of publish validation (shape, ids, duplicates,
 * dangling edges, size caps) and the lenient parse into a Graph the later passes walk.
 *
 * @internal
 */
final class GraphParser
{
    public const string NODE_ID = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param  array<mixed>  $raw
     * @return list<ValidationError>
     */
    public static function structure(array $raw): array
    {
        $errors = [];

        if (($raw['schema'] ?? null) !== Graph::SCHEMA) {
            $errors[] = ValidationError::make(ValidationCode::InvalidSchemaVersion, path: '/schema', params: ['expected' => Graph::SCHEMA]);
        }

        if (isset($raw['settings']) && ! is_array($raw['settings'])) {
            $errors[] = ValidationError::make(ValidationCode::InvalidStructure, path: '/settings', params: ['expected' => 'object']);
        }

        $nodes = $raw['nodes'] ?? null;
        $edges = $raw['edges'] ?? [];

        if (! is_array($nodes) || ! array_is_list($nodes)) {
            $errors[] = ValidationError::make(ValidationCode::InvalidStructure, path: '/nodes', params: ['expected' => 'array']);

            return $errors;
        }

        if (! is_array($edges) || ! array_is_list($edges)) {
            $errors[] = ValidationError::make(ValidationCode::InvalidStructure, path: '/edges', params: ['expected' => 'array']);

            return $errors;
        }

        $bytes = strlen((string) json_encode($raw));

        if (count($nodes) > WorkflowsConfig::graphMaxNodes()
            || count($edges) > WorkflowsConfig::graphMaxEdges()
            || $bytes > WorkflowsConfig::graphMaxBytes()) {
            $errors[] = ValidationError::make(ValidationCode::GraphTooLarge, params: [
                'nodes' => count($nodes),
                'edges' => count($edges),
                'bytes' => $bytes,
                'max_nodes' => WorkflowsConfig::graphMaxNodes(),
                'max_edges' => WorkflowsConfig::graphMaxEdges(),
                'max_bytes' => WorkflowsConfig::graphMaxBytes(),
            ]);

            return $errors;
        }

        $ids = [];

        foreach ($nodes as $index => $node) {
            $path = '/nodes/'.$index;

            if (! is_array($node)) {
                $errors[] = ValidationError::make(ValidationCode::InvalidStructure, path: $path, params: ['expected' => 'object']);

                continue;
            }

            $id = $node['id'] ?? null;

            if (! is_string($id) || preg_match(self::NODE_ID, $id) !== 1) {
                $errors[] = ValidationError::make(ValidationCode::InvalidNodeId, path: $path.'/id');

                continue;
            }

            if (isset($ids[$id])) {
                $errors[] = ValidationError::make(ValidationCode::DuplicateNode, node: $id);

                continue;
            }

            $ids[$id] = true;

            if (! is_string($node['type'] ?? null) || $node['type'] === '') {
                $errors[] = ValidationError::make(ValidationCode::InvalidStructure, node: $id, path: '/type', params: ['expected' => 'string']);
            }

            foreach (['config', 'retry', 'ui'] as $key) {
                if (array_key_exists($key, $node) && $node[$key] !== null && ! is_array($node[$key])) {
                    $errors[] = ValidationError::make(ValidationCode::InvalidStructure, node: $id, path: '/'.$key, params: ['expected' => 'object']);
                }
            }

            if (array_key_exists('escalations', $node) && (! is_array($node['escalations']) || ! array_is_list($node['escalations']))) {
                $errors[] = ValidationError::make(ValidationCode::InvalidStructure, node: $id, path: '/escalations', params: ['expected' => 'array']);
            }
        }

        foreach ($edges as $index => $edge) {
            $path = '/edges/'.$index;

            if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)
                || (isset($edge['outcome']) && ! is_string($edge['outcome']))) {
                $errors[] = ValidationError::make(ValidationCode::InvalidStructure, path: $path, params: ['expected' => 'edge']);

                continue;
            }

            $outcome = $edge['outcome'] ?? null;

            if (! isset($ids[$edge['from']]) || ! isset($ids[$edge['to']])) {
                $errors[] = ValidationError::make(
                    ValidationCode::DanglingEdge,
                    node: isset($ids[$edge['from']]) ? $edge['from'] : null,
                    edge: new EdgeReference($edge['from'], $edge['to'], $outcome),
                );
            }
        }

        return $errors;
    }

    /**
     * Parse a structurally valid payload (anything malformed is skipped, never thrown).
     *
     * @param  array<mixed>  $raw
     */
    public static function parse(array $raw): Graph
    {
        $nodes = [];

        foreach (is_array($raw['nodes'] ?? null) ? $raw['nodes'] : [] as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || isset($nodes[$node['id']])) {
                continue;
            }

            $escalations = [];

            foreach (is_array($node['escalations'] ?? null) ? $node['escalations'] : [] as $level) {
                if (is_array($level)) {
                    $escalations[] = self::stringKeyed($level);
                }
            }

            $nodes[$node['id']] = new Node(
                id: $node['id'],
                type: is_string($node['type'] ?? null) ? $node['type'] : '',
                config: is_array($node['config'] ?? null) ? self::stringKeyed($node['config']) : [],
                retry: is_array($node['retry'] ?? null) ? self::stringKeyed($node['retry']) : null,
                escalations: $escalations,
                ui: is_array($node['ui'] ?? null) ? self::stringKeyed($node['ui']) : null,
            );
        }

        $edges = [];

        foreach (is_array($raw['edges'] ?? null) ? $raw['edges'] : [] as $edge) {
            if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)) {
                continue;
            }

            $edges[] = new Edge($edge['from'], $edge['to'], isset($edge['outcome']) && is_string($edge['outcome']) ? $edge['outcome'] : null);
        }

        return new Graph(
            schema: is_string($raw['schema'] ?? null) ? $raw['schema'] : '',
            settings: is_array($raw['settings'] ?? null) ? self::stringKeyed($raw['settings']) : [],
            nodes: $nodes,
            edges: $edges,
        );
    }

    /**
     * The minimal graph a new definition starts with: a manual trigger straight to an end.
     *
     * @return array<string, mixed>
     */
    public static function minimal(): array
    {
        return [
            'schema' => Graph::SCHEMA,
            'settings' => [],
            'nodes' => [
                ['id' => 'start', 'type' => 'trigger', 'config' => ['trigger' => 'manual']],
                ['id' => 'end', 'type' => 'end', 'config' => []],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'end'],
            ],
        ];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $keyed = [];

        foreach ($value as $key => $item) {
            $keyed[(string) $key] = $item;
        }

        return $keyed;
    }
}
