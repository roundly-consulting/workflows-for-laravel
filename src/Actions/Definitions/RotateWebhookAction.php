<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;

/**
 * Gives a webhook-triggered definition a new hook id (the old address stops working at once).
 */
final readonly class RotateWebhookAction
{
    public function execute(WorkflowDefinition $definition): WorkflowDefinition
    {
        $definition->webhook_id = (string) Str::ulid();
        $definition->save();

        return $definition;
    }
}
