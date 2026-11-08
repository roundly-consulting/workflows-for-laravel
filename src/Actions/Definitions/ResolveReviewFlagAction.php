<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;
use RoundlyConsulting\Workflows\Exceptions\NothingToPublishException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Drafts;

/**
 * Dismisses one review flag an import left on the draft (the value is accepted as it is).
 */
final readonly class ResolveReviewFlagAction
{
    public function execute(WorkflowDefinition $definition, string $node, string $path): WorkflowVersion
    {
        $draft = Drafts::find($definition) ?? throw NothingToPublishException::key($definition->key);

        $flags = array_values(array_filter(
            $draft->reviewFlags(),
            static fn (ReviewFlag $flag): bool => ! $flag->is($node, $path),
        ));

        $draft->setReviewFlags($flags);
        $draft->save();

        return $draft;
    }
}
