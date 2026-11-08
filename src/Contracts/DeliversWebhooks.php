<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Queues an outbound webhook for the `webhook` step, which is registered only when an
 * implementation is available: a container binding, config `workflows.adapters.webhooks`, or the
 * webhooks-for-laravel bridge when that package is installed. Must be idempotent on the key.
 *
 * @return int|string the delivery's id
 */
interface DeliversWebhooks
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliver(?Model $owner, string $endpoint, string $event, array $payload, string $idempotencyKey): int|string;
}
