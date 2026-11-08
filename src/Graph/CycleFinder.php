<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

use Closure;

/**
 * Finds the cycles that no guarded edge interrupts. The guarded edges (a loop's `continue`, a
 * positive delay's way out) are removed first and the rest is searched with an iterative
 * white/grey/black DFS, so a strongly connected component holding one guarded and one unguarded
 * cycle is still reported.
 *
 * @internal
 */
final class CycleFinder
{
    /**
     * @param  Closure(Edge, Node): bool  $guarded  whether taking this edge out of that node bounds a cycle
     * @return list<list<string>> each cycle's node ids in order (starting at the first one found)
     */
    public static function unguarded(Graph $graph, Closure $guarded): array
    {
        $adjacency = [];

        foreach (array_keys($graph->nodes) as $id) {
            $adjacency[$id] = [];
        }

        foreach ($graph->edges as $edge) {
            $from = $graph->nodes[$edge->from] ?? null;

            if ($from !== null && isset($adjacency[$edge->to]) && ! $guarded($edge, $from)) {
                $adjacency[$edge->from][] = $edge->to;
            }
        }

        $color = array_fill_keys(array_keys($adjacency), 0); // 0 white, 1 grey, 2 black
        $cycles = [];
        $reported = [];

        foreach (array_keys($adjacency) as $root) {
            if ($color[$root] !== 0) {
                continue;
            }

            /** @var list<array{0: string, 1: int}> $stack */
            $stack = [[$root, 0]];
            $path = [$root];
            $color[$root] = 1;

            while ($stack !== []) {
                $top = count($stack) - 1;
                [$id, $index] = $stack[$top];

                if ($index >= count($adjacency[$id])) {
                    $color[$id] = 2;
                    array_pop($stack);
                    array_pop($path);

                    continue;
                }

                $stack[$top][1] = $index + 1;
                $next = $adjacency[$id][$index];

                if ($color[$next] === 0) {
                    $color[$next] = 1;
                    $stack[] = [$next, 0];
                    $path[] = $next;
                } elseif ($color[$next] === 1) {
                    $start = array_search($next, $path, true);
                    $cycle = array_slice($path, (int) $start);
                    $signature = self::signature($cycle);

                    if (! isset($reported[$signature])) {
                        $reported[$signature] = true;
                        $cycles[] = $cycle;
                    }
                }
            }
        }

        return $cycles;
    }

    /**
     * @param  list<string>  $cycle
     */
    private static function signature(array $cycle): string
    {
        $sorted = $cycle;
        sort($sorted);

        return implode('|', $sorted);
    }
}
