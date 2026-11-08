<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;

/**
 * A version: number, status, graph, trigger, actor mode, checksum, import review flags and its
 * publisher reference.
 *
 * @property WorkflowVersion $resource
 */
final class VersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->resource;

        return [
            'id' => $version->getKey(),
            'number' => $version->number,
            'status' => Enums::render($version->status),
            'graph' => $version->graph,
            'trigger' => $version->trigger,
            'actor' => Enums::render($version->actor_mode),
            'checksum' => $version->checksum,
            'review_flags' => $version->review_flags ?? [],
            'published_at' => $version->published_at?->toIso8601String(),
            'published_by' => References::user($version->relationLoaded('publisher') ? $version->publisher : null, $version->publisher_type, $version->publisher_id, $request),
        ];
    }
}
