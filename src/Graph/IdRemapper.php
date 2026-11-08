<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

/**
 * Gives every node of a graph a fresh id (`n_` + 10 lowercase base32 characters) and rewrites
 * every reference through the map: edges, `steps.<id>.…` refs and condition fields anywhere in
 * node configs and escalations, and strings at `format: node-id` schema paths. `ui` stays opaque.
 *
 * @internal
 */
final class IdRemapper
{
    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, array<string, mixed>>  $schemas  node type => config schema
     */
    public static function remap(array $graph, array $schemas = []): RemappedGraph
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $map = [];

        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['id'] ?? null)) {
                do {
                    $id = self::fresh();
                } while (in_array($id, $map, true) || isset($map[$id]));

                $map[$node['id']] = $id;
            }
        }

        $remapped = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            $node['id'] = $map[$node['id']];

            if (is_array($node['config'] ?? null)) {
                $node['config'] = self::rewrite($node['config'], $map, $schemas[$type] ?? []);
            }

            if (is_array($node['escalations'] ?? null)) {
                $node['escalations'] = self::rewrite($node['escalations'], $map, []);
            }

            $remapped[] = $node;
        }

        $edges = [];

        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $edge) {
            if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)) {
                continue;
            }

            $edge['from'] = $map[$edge['from']] ?? $edge['from'];
            $edge['to'] = $map[$edge['to']] ?? $edge['to'];
            $edges[] = $edge;
        }

        $graph['nodes'] = $remapped;
        $graph['edges'] = $edges;

        return new RemappedGraph($graph, $map);
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<array-key, mixed>  $schema
     */
    private static function rewrite(mixed $value, array $map, array $schema): mixed
    {
        if (is_string($value)) {
            if (($schema['format'] ?? null) === 'node-id' && isset($map[$value])) {
                return $map[$value];
            }

            if (preg_match('/^steps\.([a-z][a-z0-9_]*)(\..*)?$/', $value, $matches) === 1 && isset($map[$matches[1]])) {
                return 'steps.'.$map[$matches[1]].($matches[2] ?? '');
            }

            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

        foreach ($value as $key => $item) {
            $child = array_is_list($value) ? $items : (is_array($properties[$key] ?? null) ? $properties[$key] : []);
            $value[$key] = self::rewrite($item, $map, $child);
        }

        return $value;
    }

    private static function fresh(): string
    {
        $id = 'n_';

        for ($i = 0; $i < 10; $i++) {
            $id .= self::ALPHABET[mt_rand(0, 31)];
        }

        return $id;
    }
}
