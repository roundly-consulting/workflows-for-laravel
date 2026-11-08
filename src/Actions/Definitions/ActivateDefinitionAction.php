<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Exceptions\NothingToPublishException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;

/**
 * Re-activates a retired definition on its current version.
 */
final readonly class ActivateDefinitionAction
{
    public function execute(WorkflowDefinition $definition): WorkflowDefinition
    {
        if ($definition->current_version_id === null) {
            throw NothingToPublishException::key($definition->key);
        }

        $definition->status = DefinitionStatus::Active;
        $definition->save();

        return $definition;
    }
}
