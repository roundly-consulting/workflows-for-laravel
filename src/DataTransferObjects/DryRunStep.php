<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One node a dry run walked: its outcome, whether the outcome was assumed, and the virtual time.
 */
final readonly class DryRunStep
{
    public function __construct(
        public string $node,
        public string $type,
        public ?string $outcome,
        public bool $assumed,
        public string $at,
    ) {}

    /**
     * @return array{node: string, type: string, outcome: ?string, assumed: bool, at: string}
     */
    public function toArray(): array
    {
        return ['node' => $this->node, 'type' => $this->type, 'outcome' => $this->outcome, 'assumed' => $this->assumed, 'at' => $this->at];
    }
}
