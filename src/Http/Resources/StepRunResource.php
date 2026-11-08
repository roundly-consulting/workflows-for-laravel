<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Models\StepRun;

/**
 * One attempt in the step log: node, type, visit, attempt, status, outcome, input, output, error
 * (code, message, retryable — never an exception class; an unknown exception renders the generic
 * line) and timing. `output.request_id` of an approval step is the round to decide `within()`.
 *
 * @property StepRun $resource
 */
final class StepRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            'node' => $run->node_id,
            'type' => $run->node_type,
            'visit' => $run->visit,
            'attempt' => $run->attempt,
            'status' => Enums::render($run->status),
            'outcome' => $run->outcome,
            'input' => $run->input,
            'output' => $run->output,
            'error' => $run->error_code === null ? null : [
                'code' => $run->error_code,
                'message' => InstanceResource::message($run->error_code, $run->error_message),
                'retryable' => $run->error_retryable,
            ],
            'scheduled_for' => $run->scheduled_for?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
