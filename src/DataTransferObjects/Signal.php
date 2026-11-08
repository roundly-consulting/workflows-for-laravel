<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\SignalKind;

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
}
