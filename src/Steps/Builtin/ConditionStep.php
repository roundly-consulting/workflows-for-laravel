<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;

/**
 * `{when: condition}` → `true` or `false`. Pure.
 *
 * @internal
 */
final class ConditionStep extends BuiltinStep
{
    public function key(): string
    {
        return 'condition';
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
            'required' => ['when'],
            'additionalProperties' => false,
            'properties' => ['when' => ['$ref' => '#/$defs/condition']],
        ];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('true'), Outcome::of('false')];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        return ConditionValidator::validate($config['when'] ?? null, $scope, '/when');
    }

    public function handle(StepContext $context): StepResult
    {
        $when = $context->config()['when'] ?? [];

        return StepResult::complete(is_array($when) && Evaluator::evaluate($when, $context->scope()) ? 'true' : 'false');
    }

    public function simulate(StepContext $context): StepResult
    {
        return $this->handle($context);
    }
}
