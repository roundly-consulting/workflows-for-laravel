<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * A side effect a simulated step would have in a real run (`StepResult::wouldDo()`).
 */
final readonly class SideEffect
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $description,
        public array $details = [],
    ) {}
}
