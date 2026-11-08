<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Drafts;

/**
 * A definition: key, name, subject type (registry key), status, owner reference, current
 * version number and whether a draft exists.
 *
 * @property WorkflowDefinition $resource
 */
final class DefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->resource;

        return [
            'id' => $definition->getKey(),
            'key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'subject_type' => $definition->subject_key,
            'status' => Enums::render($definition->status),
            'owner' => References::owner($definition->relationLoaded('owner') ? $definition->owner : null, $definition->owner_type, $definition->owner_id, $request),
            'current_version' => $definition->currentVersion?->number,
            'draft' => Drafts::find($definition) !== null,
            'created_at' => $definition->created_at?->toIso8601String(),
            'updated_at' => $definition->updated_at?->toIso8601String(),
        ];
    }
}
