<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * A loop counter: `continue` for the first `max` visits, then `exhausted`. One of the two node
 * types that make a cycle legal.
 *
 * @internal
 */
final class LoopStep extends BuiltinStep
{
    public function key(): string
    {
        return 'loop';
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
            'required' => ['max'],
            'additionalProperties' => false,
            'properties' => ['max' => ['type' => 'integer', 'minimum' => 1]],
        ];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('continue'), Outcome::of('exhausted')];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $max = $config['max'] ?? null;

        if (is_int($max) && $max > WorkflowsConfig::maxLoopIterations()) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $scope->node?->id, path: '/max', params: ['keyword' => 'maximum', 'expected' => (string) WorkflowsConfig::maxLoopIterations()])];
        }

        return [];
    }

    public function handle(StepContext $context): StepResult
    {
        $max = $context->config()['max'] ?? 1;

        return StepResult::complete($context->visit() <= (is_int($max) ? $max : 1) ? 'continue' : 'exhausted', ['iteration' => $context->visit()]);
    }

    public function simulate(StepContext $context): StepResult
    {
        return $this->handle($context);
    }
}
