<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Definitions;

/**
 * Brings back a soft-deleted definition, so its key can be used again.
 */
final readonly class RestoreDefinitionAction
{
    public function execute(string $key, ?Model $owner): WorkflowDefinition
    {
        $definition = Definitions::find($key, $owner, withTrashed: true);

        if ($definition->trashed()) {
            $definition->restore();
        }

        return $definition;
    }
}
