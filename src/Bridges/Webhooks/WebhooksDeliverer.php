<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\WebhooksManager;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;

/**
 * The `webhook` step through webhooks-for-laravel: the endpoint is looked up in the definition
 * owner's scope (`Webhooks::for($owner)->outbound()->find()`; an app-level definition only finds
 * owner-less endpoints), then `send()` queues the delivery with the step's idempotency key as its
 * id — on top of the step's `once()`, a repeat returns the first delivery and queues nothing.
 * Returns the delivery's `msg_` id, the one its delivery log shows.
 *
 * @internal
 */
final readonly class WebhooksDeliverer implements DeliversWebhooks
{
    public function __construct(
        private WebhooksManager $webhooks,
    ) {}

    public function deliver(?Model $owner, string $endpoint, string $event, array $payload, string $idempotencyKey): string
    {
        if (! self::fitsKeyType($endpoint)) {
            throw EndpointNotFoundException::make();
        }

        if ($owner !== null) {
            $outbound = $this->webhooks->for($owner)->outbound();

            return $outbound->send($outbound->find($endpoint), $event, $payload, $idempotencyKey)->webhookId();
        }

        $found = $this->webhooks->outbound()->find($endpoint);

        // Owners must match exactly: an app-level workflow never sends through an owned endpoint.
        if ($found->owner_type !== null || $found->owner_id !== null) {
            throw EndpointNotFoundException::make();
        }

        return $this->webhooks->send($found, $event, $payload, $idempotencyKey)->webhookId();
    }

    /**
     * An id that can't be a key of the configured type is "not found" — never a query error
     * (a uuid column refuses a non-uuid string on Postgres).
     */
    private static function fitsKeyType(string $endpoint): bool
    {
        return match ((new OutboundEndpoint)->configuredKeyType()) {
            KeyType::BigInt => preg_match('/^[0-9]{1,19}$/D', $endpoint) === 1,
            KeyType::Uuid => Str::isUuid($endpoint),
            KeyType::Ulid => Str::isUlid($endpoint),
        };
    }
}
