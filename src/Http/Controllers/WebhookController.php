<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * `POST {prefix}/hooks/{hook}`: starts the definition whose `webhook_id` is `{hook}` with the
 * JSON body as the trigger payload. 202 `{instance, created}`; 429 (`Retry-After: 60`) for a start
 * the rate limit refused (its key stays free); 404 for an unknown or retired
 * hook (and for a subject of another owner); 422 for a missing idempotency key or a body that is
 * not a JSON object within `webhooks.max_bytes`. Signatures are the route middleware's job.
 */
final readonly class WebhookController
{
    public function __construct(
        private WorkflowsManager $workflows,
    ) {}

    public function __invoke(Request $request, string $hook): JsonResponse
    {
        $body = $request->getContent();

        if (strlen($body) > WorkflowsConfig::webhooksMaxBytes()) {
            return new JsonResponse(['code' => 'payload_too_large'], 413);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || array_is_list($payload) && $payload !== []) {
            return new JsonResponse(['code' => 'invalid_payload'], 422);
        }

        try {
            $result = $this->workflows->triggers()->webhook($hook, GraphParser::stringKeyed($payload), $request->headers->all());
        } catch (WorkflowsException $exception) {
            $status = match ($exception->errorCode()) {
                'definition_not_active', 'unknown_definition', 'subject_owner_mismatch' => 404,
                default => $exception->status(),
            };

            return new JsonResponse(['code' => $exception->errorCode(), 'message' => $exception->getMessage()], $status);
        }

        if ($result->wasRefused()) {
            // The rate limit refused it and left the key free: the sender should retry.
            return new JsonResponse([
                'code' => $result->refused,
                'message' => $result->instance?->failure_message,
                'instance' => $result->instance?->ulid,
                'created' => false,
            ], 429, ['Retry-After' => '60']);
        }

        return new JsonResponse(['instance' => $result->instance?->ulid, 'created' => $result->created], 202);
    }
}
