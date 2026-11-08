<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * A side effect a real run would have: simulated (`simulated`, with the type's description), or
 * merely listed (`would_run`) when the type has no simulate().
 */
final readonly class WouldBeAction
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $node,
        public string $type,
        public string $status,
        public ?string $description = null,
        public array $details = [],
    ) {}

    /**
     * @return array{node: string, type: string, status: string, description: ?string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['node' => $this->node, 'type' => $this->type, 'status' => $this->status, 'description' => $this->description, 'details' => $this->details];
    }
}
