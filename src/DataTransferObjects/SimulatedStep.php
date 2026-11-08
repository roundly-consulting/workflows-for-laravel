<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * What a dry run made of one node: the outcome taken, whether it was assumed, the output, and the
 * side effects a real run would have.
 *
 * @internal
 */
final readonly class SimulatedStep
{
    /**
     * @param  array<string, mixed>  $output
     * @param  list<WouldBeAction>  $actions
     */
    public function __construct(
        public string $outcome,
        public bool $assumed,
        public array $output,
        public array $actions,
    ) {}
}
