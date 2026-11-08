<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;

/**
 * Ends the instance: completed, or `failed` (code `ended_by_graph`, with the optional reason).
 *
 * @internal
 */
final class EndStep extends BuiltinStep
{
    public function key(): string
    {
        return 'end';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function transactional(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'status' => ['enum' => ['completed', 'failed']],
                'reason' => ['type' => 'string', 'maxLength' => 500],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        return [];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::complete('end');
    }

    public function simulate(StepContext $context): StepResult
    {
        return $this->handle($context);
    }
}
