<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;

/**
 * A portable template payload.
 *
 * @property TemplatePayload $resource
 */
final class TemplateResource extends JsonResource
{
    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
