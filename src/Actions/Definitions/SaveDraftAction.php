<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use Illuminate\Support\Arr;
use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Events\DraftSaved;
use RoundlyConsulting\Workflows\Exceptions\DraftConflictException;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Drafts;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * Saves a graph into the definition's one draft (created from the current version when there is
 * none), under the definition row lock. Only the structure is validated, so a builder can store
 * half-finished graphs; `expectedChecksum` refuses a save over a draft that changed meanwhile.
 * Editing a path flagged on import clears its flag.
 */
final readonly class SaveDraftAction
{
    /**
     * @param  array<string, mixed>  $graph
     */
    public function execute(WorkflowDefinition $definition, array $graph, ?string $expectedChecksum = null): WorkflowVersion
    {
        $structural = GraphParser::structure($graph);

        if ($structural !== []) {
            throw InvalidGraphException::report(new ValidationReport($structural));
        }

        $model = WorkflowModels::definition();
        $connection = (new $model)->getConnection();

        $draft = $connection->transaction(static function () use ($definition, $graph, $expectedChecksum, $model): WorkflowVersion {
            $locked = $model::query()->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
            $draft = Drafts::findOrCreate($locked);

            if ($expectedChecksum !== null && $expectedChecksum !== $draft->checksum) {
                throw DraftConflictException::key($locked->key);
            }

            $before = $draft->graph;
            Drafts::fill($draft, $graph);
            $draft->setReviewFlags(self::remainingFlags($draft->reviewFlags(), $before, $graph));
            $draft->save();

            return $draft;
        });

        Events::dispatch(new DraftSaved($definition, $draft));

        return $draft;
    }

    /**
     * Flags whose value changed (and is no longer null) are resolved.
     *
     * @param  list<ReviewFlag>  $flags
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<ReviewFlag>
     */
    private static function remainingFlags(array $flags, array $before, array $after): array
    {
        $remaining = [];

        foreach ($flags as $flag) {
            $old = self::at($before, $flag->node, $flag->path);
            $new = self::at($after, $flag->node, $flag->path);

            if ($new === null || $new === $old) {
                $remaining[] = $flag;
            }
        }

        return $remaining;
    }

    /**
     * The value at a flag path: `/settings/...` in the graph, else a pointer inside the node.
     *
     * @param  array<string, mixed>  $graph
     */
    private static function at(array $graph, string $node, string $path): mixed
    {
        $dotted = str_replace('/', '.', trim($path, '/'));

        if ($node === '' || $node === '*') {
            return Arr::get($graph, $dotted);
        }

        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $node) {
                return Arr::get($candidate, $dotted);
            }
        }

        return null;
    }
}
