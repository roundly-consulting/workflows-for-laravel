<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\DataTransferObjects\CatalogueEntry;

/**
 * One step type for a builder UI (key, label, config schema, outcomes).
 *
 * @property CatalogueEntry $resource
 */
final class CatalogueEntryResource extends JsonResource
{
    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
