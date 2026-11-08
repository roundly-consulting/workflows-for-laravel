<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * One app event delivered to workflows: its trigger key, subject, payload, idempotency key,
 * triggering user and (optionally) explicit owner.
 */
final readonly class EmitData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $event,
        public ?Model $subject = null,
        public array $payload = [],
        public ?string $idempotencyKey = null,
        public ?Model $user = null,
        public ?Model $owner = null,
    ) {}
}
