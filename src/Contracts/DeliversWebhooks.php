<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Queues an outbound webhook for the `webhook` step. Only when a host binds an implementation
 * (config `workflows.adapters.webhooks`) is the step type registered; the webhooks-for-laravel
 * bridge provides one once that package is released. Must be idempotent on the key.
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
