<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;

/**
 * A machine-readable validation report (per node and edge).
 *
 * @property ValidationReport $resource
 */
final class ValidationReportResource extends JsonResource
{
    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
