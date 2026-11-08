<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;

/**
 * A subject type's field schema for pickers (registry key, never the class).
 *
 * @property SubjectTypeDefinition $resource
 */
final class SubjectTypeResource extends JsonResource
{
    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
