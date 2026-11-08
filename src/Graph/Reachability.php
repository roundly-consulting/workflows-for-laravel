<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

/**
 * Breadth-first reachability from the trigger.
 *
 * @internal
 */
final class Reachability
{
    /**
     * @return list<string> the ids no path from the start reaches, in graph order
     */
    public static function unreachable(Graph $graph, string $start): array
    {
        $seen = [$start => true];
        $queue = [$start];

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($graph->outgoing($id) as $edge) {
                if (! isset($seen[$edge->to])) {
                    $seen[$edge->to] = true;
                    $queue[] = $edge->to;
                }
            }
        }

        return array_values(array_filter(array_keys($graph->nodes), static fn (string $id): bool => ! isset($seen[$id])));
    }

    /**
     * Every node from which `$target` can be reached (its ancestors), excluding itself.
     *
     * @return array<string, true>
     */
    public static function ancestors(Graph $graph, string $target): array
    {
        $seen = [];
        $queue = [$target];

        while ($queue !== []) {
            $id = array_shift($queue);

            foreach ($graph->incoming($id) as $edge) {
                if (! isset($seen[$edge->from]) && $edge->from !== $target) {
                    $seen[$edge->from] = true;
                    $queue[] = $edge->from;
                }
            }
        }

        return $seen;
    }
}
