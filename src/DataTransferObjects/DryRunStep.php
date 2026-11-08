<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One node a dry run walked: its outcome, whether the outcome was assumed, the virtual time and
 * the input the step would see (its config with every reference resolved, as a real run records).
 */
final readonly class DryRunStep
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $node,
        public string $type,
        public ?string $outcome,
        public bool $assumed,
        public string $at,
        public array $input = [],
    ) {}

    /**
     * @return array{node: string, type: string, outcome: ?string, assumed: bool, at: string, input: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['node' => $this->node, 'type' => $this->type, 'outcome' => $this->outcome, 'assumed' => $this->assumed, 'at' => $this->at, 'input' => $this->input];
    }
}
