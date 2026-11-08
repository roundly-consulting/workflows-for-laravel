<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;

/**
 * An escalation level of a waiting step: its level (1 = the first), when it is due, its status and
 * when it fired. No morph columns.
 *
 * @property WorkflowEscalation $resource
 */
final class EscalationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $escalation = $this->resource;

        return [
            'level' => $escalation->level,
            'due_at' => $escalation->due_at->toIso8601String(),
            'status' => Enums::render($escalation->status),
            'fired_at' => $escalation->fired_at?->toIso8601String(),
        ];
    }
}
