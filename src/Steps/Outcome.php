<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

/**
 * One outcome a step type can end with. A required outcome needs exactly one edge.
 */
final readonly class Outcome
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = true,
    ) {}

    public static function next(): self
    {
        return new self('next', __('workflows::steps.outcomes.next'), true);
    }

    public static function of(string $key, bool $required = true, ?string $label = null): self
    {
        $line = __('workflows::steps.outcomes.'.$key);

        return new self($key, $label ?? (is_string($line) && $line !== 'workflows::steps.outcomes.'.$key ? $line : $key), $required);
    }
}
