<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Graph\GraphParser;

/**
 * What resumed a waiting step: a timer, an event (with its payload), a met condition, a probe
 * right after the step started waiting, or a timeout (with the outcome its edge takes).
 */
final readonly class Signal
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public SignalKind $kind,
        public ?string $key = null,
        public array $payload = [],
        public ?string $outcome = null,
        public int|string|null $waitId = null,
    ) {}

    /**
     * As stored on the step run it resumed (`resume_signal`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'key' => $this->key, 'payload' => $this->payload, 'outcome' => $this->outcome, 'wait_id' => $this->waitId];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        $kind = is_string($stored['kind'] ?? null) ? SignalKind::tryFrom($stored['kind']) : null;

        if ($stored === null || $kind === null) {
            return null;
        }

        $waitId = $stored['wait_id'] ?? null;

        return new self(
            kind: $kind,
            key: is_string($stored['key'] ?? null) ? $stored['key'] : null,
            payload: is_array($stored['payload'] ?? null) ? GraphParser::stringKeyed($stored['payload']) : [],
            outcome: is_string($stored['outcome'] ?? null) ? $stored['outcome'] : null,
            waitId: is_int($waitId) || is_string($waitId) ? $waitId : null,
        );
    }
}
