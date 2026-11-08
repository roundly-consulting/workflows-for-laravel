<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * An instance: definition key, version number, status, subject / owner / starter references,
 * trigger, current node, step count, depth and failure. An unknown exception's message is never
 * rendered (it routinely carries class names): the generic translated line is.
 *
 * @property WorkflowInstance $resource
 */
final class InstanceResource extends JsonResource
{
    /**
     * Failure codes whose messages were written by the package or a handler author.
     */
    public const array OPAQUE = ['exception', 'worker_timeout'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $instance = $this->resource;

        return [
            'id' => $instance->ulid,
            'definition' => $instance->relationLoaded('definition') ? $instance->definition?->key : null,
            'version' => $instance->relationLoaded('version') ? $instance->version?->number : null,
            'status' => Enums::render($instance->status),
            'subject' => References::subject($instance->relationLoaded('subject') ? $instance->subject : null, $instance->subject_type, $instance->subject_id, $request),
            'subject_type' => $instance->subject_key,
            'owner' => References::owner($instance->relationLoaded('owner') ? $instance->owner : null, $instance->owner_type, $instance->owner_id, $request),
            'trigger' => ['type' => $instance->trigger_type->value, 'key' => $instance->trigger_key],
            'started_by' => References::user($instance->relationLoaded('starter') ? $instance->starter : null, $instance->starter_type, $instance->starter_id, $request),
            'current_node' => $instance->current_node_id,
            'steps_count' => $instance->steps_count,
            'depth' => $instance->depth,
            'failure' => $instance->failure_code === null ? null : [
                'code' => $instance->failure_code,
                'message' => self::message($instance->failure_code, $instance->failure_message),
                'node' => $instance->failed_node_id,
            ],
            'started_at' => $instance->started_at?->toIso8601String(),
            'ended_at' => $instance->ended_at?->toIso8601String(),
        ];
    }

    public static function message(?string $code, ?string $message): ?string
    {
        return in_array($code, self::OPAQUE, true) ? Messages::get('failures.exception') : $message;
    }
}
