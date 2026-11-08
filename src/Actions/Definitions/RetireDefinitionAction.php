<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Events\DefinitionRetired;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Events;

/**
 * Stops new starts (start, emit, schedule and webhook refuse); running instances finish.
 */
final readonly class RetireDefinitionAction
{
    public function execute(WorkflowDefinition $definition): WorkflowDefinition
    {
        $definition->status = DefinitionStatus::Retired;
        $definition->save();

        Events::dispatch(new DefinitionRetired($definition));

        return $definition;
    }
}
