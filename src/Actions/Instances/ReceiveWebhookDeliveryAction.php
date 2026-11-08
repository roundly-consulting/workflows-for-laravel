<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\DataTransferObjects\WebhookDelivery;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Exceptions\EndpointOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\WebhookEndpoints;
use RoundlyConsulting\Workflows\Support\WebhookSubjects;

/**
 * Starts the workflow a webhooks-package inbound endpoint is wired to: the active definition of
 * the endpoint's owner whose webhook trigger names the endpoint (`trigger.endpoint`; both owners
 * null is a match). The delivery id is the idempotency key, so a redelivery or a webhooks replay
 * returns the first instance and never runs the workflow again. A definition of another owner
 * naming the endpoint is refused as `owner_mismatch`.
 */
final readonly class ReceiveWebhookDeliveryAction
{
    public function __construct(
        private StartInstanceAction $start,
        private WebhookSubjects $subjects,
        private RestoresOwnerContext $context,
    ) {}

    /**
     * @throws UnknownDefinitionException|EndpointOwnerMismatchException|SubjectOwnerMismatchException
     */
    public function execute(WebhookDelivery $delivery): StartResult
    {
        $definition = WebhookEndpoints::definitions($delivery->endpoint, $delivery->owner)[0] ?? null;
        $version = $definition?->currentVersion;

        if ($definition === null || ! $version instanceof WorkflowVersion) {
            throw WebhookEndpoints::namedAnywhere($delivery->endpoint)
                ? EndpointOwnerMismatchException::endpoint()
                : UnknownDefinitionException::endpoint($delivery->endpoint);
        }

        $owner = Definitions::owner($definition);
        $subject = $this->context->run($owner, fn (): ?Model => $this->subjects->find($definition, $version->trigger, $delivery->payload));

        return $this->start->execute(new StartData(
            key: $definition->key,
            subject: $subject,
            owner: $owner,
            idempotencyKey: 'delivery:'.$delivery->deliveryId,
            trigger: TriggerType::Webhook,
            payload: ['payload' => $delivery->payload],
        ));
    }
}
