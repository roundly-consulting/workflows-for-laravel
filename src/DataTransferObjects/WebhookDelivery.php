<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * A verified inbound webhook delivery, as the webhooks-for-laravel bridge hands it over: the
 * inbound endpoint it arrived on and that endpoint's owner (null for an app-level endpoint), the
 * delivery id (the start's idempotency key, so a replay finds the first instance) and the
 * sanitized payload. Signatures, replay protection and dedupe already happened upstream.
 */
final readonly class WebhookDelivery
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public int|string $endpoint,
        public ?Model $owner,
        public string $deliveryId,
        public array $payload = [],
    ) {}
}
