<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Support\Engine\Signals;

/**
 * Delivers a signal key to the pending event waits listening for it (internal `@` keys skip the
 * owner and subject checks; they are unique).
 *
 * @internal
 */
final readonly class SignalAction
{
    public function __construct(
        private Signals $signals,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(string $key, ?Model $subject = null, array $payload = [], ?Model $owner = null): int
    {
        return $this->signals->send($key, $subject, $payload, $owner, internal: str_starts_with($key, '@'));
    }
}
