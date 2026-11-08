<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Versions;

use RoundlyConsulting\Workflows\DataTransferObjects\EdgeChange;
use RoundlyConsulting\Workflows\DataTransferObjects\EdgeReference;
use RoundlyConsulting\Workflows\DataTransferObjects\NodeChange;
use RoundlyConsulting\Workflows\DataTransferObjects\VersionDiff;
use RoundlyConsulting\Workflows\Exceptions\InvalidDiffException;
use RoundlyConsulting\Workflows\Graph\Canonicalizer;
use RoundlyConsulting\Workflows\Graph\Edge;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Pointers;

/**
 * A structural comparison of two versions of one definition (pure computation; the UI renders
 * it). `ui` changes count as moves only, so a compare view can ignore them.
 */
final readonly class DiffVersionsAction
{
    public function execute(WorkflowVersion $from, WorkflowVersion $to): VersionDiff
    {
        if ((string) $from->definition_id !== (string) $to->definition_id) {
            throw InvalidDiffException::differentDefinitions();
        }

        if ($from->checksum === $to->checksum) {
            return new VersionDiff($from->number, $to->number, true);
        }

        $a = $from->parsedGraph();
        $b = $to->parsedGraph();

        $added = array_values(array_diff(array_keys($b->nodes), array_keys($a->nodes)));
        $removed = array_values(array_diff(array_keys($a->nodes), array_keys($b->nodes)));
        $changed = [];
        $moved = [];

        foreach ($a->nodes as $id => $old) {
            $new = $b->node($id);

            if ($new === null) {
                continue;
            }

            $config = Pointers::diff(Canonicalizer::canonical($old->config), Canonicalizer::canonical($new->config));
            $typeChanged = $old->type !== $new->type;
            $retryChanged = ! Canonicalizer::equal($old->retry, $new->retry);
            $escalationsChanged = ! Canonicalizer::equal($old->escalations, $new->escalations);

            if ($typeChanged || $config !== [] || $retryChanged || $escalationsChanged) {
                $changed[] = new NodeChange($id, $typeChanged, $config, $retryChanged, $escalationsChanged);
            }

            if (! Canonicalizer::equal($old->ui, $new->ui)) {
                $moved[] = $id;
            }
        }

        $keyOf = static fn (Edge $edge): string => $edge->from.'>'.$edge->to.'>'.($edge->outcome ?? '');
        $pairOf = static fn (Edge $edge): string => $edge->from.'>'.$edge->to;

        $oldEdges = [];
        foreach ($a->edges as $edge) {
            $oldEdges[$keyOf($edge)] = $edge;
        }

        $newEdges = [];
        foreach ($b->edges as $edge) {
            $newEdges[$keyOf($edge)] = $edge;
        }

        $edgesAdded = array_diff_key($newEdges, $oldEdges);
        $edgesRemoved = array_diff_key($oldEdges, $newEdges);
        $relabelled = [];

        foreach ($edgesRemoved as $key => $old) {
            foreach ($edgesAdded as $newKey => $new) {
                if ($pairOf($old) === $pairOf($new)) {
                    $relabelled[] = new EdgeChange($old->from, $old->to, $old->outcome, $new->outcome);
                    unset($edgesRemoved[$key], $edgesAdded[$newKey]);

                    break;
                }
            }
        }

        return new VersionDiff(
            from: $from->number,
            to: $to->number,
            identical: false,
            nodesAdded: $added,
            nodesRemoved: $removed,
            nodesChanged: $changed,
            nodesMoved: $moved,
            edgesAdded: array_values(array_map(static fn (Edge $edge): EdgeReference => $edge->reference(), $edgesAdded)),
            edgesRemoved: array_values(array_map(static fn (Edge $edge): EdgeReference => $edge->reference(), $edgesRemoved)),
            edgesRelabelled: $relabelled,
            triggerChanges: Pointers::diff(Canonicalizer::canonical($from->trigger), Canonicalizer::canonical($to->trigger)),
            settingsChanges: Pointers::diff(Canonicalizer::canonical($from->settings), Canonicalizer::canonical($to->settings)),
        );
    }
}
