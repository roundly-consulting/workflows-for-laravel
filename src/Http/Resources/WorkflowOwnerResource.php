<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How a workflow's owner renders: its key and class basename only, never a class name.
 * Point `workflows.resources.owner` at your own JsonResource to add a name or avatar; it
 * receives the model.
 *
 * @property Model $resource
 */
final class WorkflowOwnerResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
