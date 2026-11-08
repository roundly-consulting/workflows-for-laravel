<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use RoundlyConsulting\Workflows\Exceptions\NothingToPublishException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Drafts;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * Throws the draft away. A definition never published keeps its version 1, reset to the
 * minimal graph.
 */
final readonly class DiscardDraftAction
{
    public function execute(WorkflowDefinition $definition): WorkflowDefinition
    {
        $model = WorkflowModels::definition();
        $connection = (new $model)->getConnection();

        $connection->transaction(static function () use ($definition, $model): void {
            $locked = $model::query()->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
            $draft = Drafts::find($locked);

            if ($draft === null) {
                throw NothingToPublishException::key($locked->key);
            }

            if ($locked->current_version_id === null) {
                Drafts::fill($draft, GraphParser::minimal());
                $draft->review_flags = null;
                $draft->save();

                return;
            }

            $draft->forceDelete();
        });

        return $definition->refresh();
    }
}
