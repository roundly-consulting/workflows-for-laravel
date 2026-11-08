<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\DataTransferObjects\OverlayReport;

/**
 * The run overlay of a version: counts per node.
 *
 * @property OverlayReport $resource
 */
final class OverlayResource extends JsonResource
{
    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
