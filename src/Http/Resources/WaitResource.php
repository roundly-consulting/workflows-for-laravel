<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Models\WorkflowWait;

/**
 * A pending wait of an instance: its kind (timer, event, condition, timeout, poll), when it is
 * due, the event it listens for, and its status. No morph columns.
 *
 * @property WorkflowWait $resource
 */
final class WaitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $wait = $this->resource;

        return [
            'kind' => Enums::render($wait->kind),
            'due_at' => $wait->due_at?->toIso8601String(),
            'event' => $wait->event_key,
            'status' => Enums::render($wait->status),
        ];
    }
}
