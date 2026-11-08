<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Webhooks;

use RoundlyConsulting\Webhooks\Contracts\InboundHandler;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Workflows\DataTransferObjects\WebhookDelivery;
use RoundlyConsulting\Workflows\Exceptions\EndpointOwnerMismatchException;
use RoundlyConsulting\Workflows\Facades\Workflows;

/**
 * The inbound handler behind the `workflows` alias: a verified delivery starts the workflow whose
 * webhook trigger names the endpoint, with the delivery id as the idempotency key — a webhooks
 * replay returns the first instance and never runs the workflow again. A refusal (no workflow
 * wired, another owner's workflow) throws, so the delivery log shows it failed.
 *
 * @internal
 */
final class StartWorkflowFromWebhook implements InboundHandler
{
    public function handle(InboundWebhook $webhook): void
    {
        $endpoint = $webhook->endpoint;
        $owner = $webhook->owner();

        // An owned endpoint whose owner is gone never falls back to the app-wide workflows.
        if ($owner === null && ($endpoint->owner_type !== null || $endpoint->owner_id !== null)) {
            throw EndpointOwnerMismatchException::endpoint();
        }

        // Through the facade, so Workflows::fake() records it.
        Workflows::triggers()->delivery(new WebhookDelivery($endpoint->id, $owner, $webhook->deliveryId, $webhook->payload));
    }
}
