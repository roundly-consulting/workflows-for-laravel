<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;

/**
 * `{cases: [{outcome, when}], default}`: the first true case wins, else the default. Every case
 * outcome and the default need an edge. Pure.
 *
 * @internal
 */
final class BranchStep extends BuiltinStep
{
    public const string OUTCOME = '/^[a-z][a-z0-9_]{0,31}$/';

    public function key(): string
    {
        return 'branch';
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
            'required' => ['cases', 'default'],
            'additionalProperties' => false,
            'properties' => [
                'cases' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'required' => ['outcome', 'when'],
                        'additionalProperties' => false,
                        'properties' => [
                            'outcome' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,31}$'],
                            'when' => ['$ref' => '#/$defs/condition'],
                        ],
                    ],
                ],
                'default' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,31}$'],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        $outcomes = [];

        foreach (is_array($config['cases'] ?? null) ? $config['cases'] : [] as $case) {
            if (is_array($case) && is_string($case['outcome'] ?? null)) {
                $outcomes[$case['outcome']] = Outcome::of($case['outcome']);
            }
        }

        if (is_string($config['default'] ?? null)) {
            $outcomes[$config['default']] = Outcome::of($config['default']);
        }

        return array_values($outcomes);
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $errors = [];
        $seen = [];
        $node = $scope->node?->id;

        foreach (is_array($config['cases'] ?? null) ? $config['cases'] : [] as $index => $case) {
            if (! is_array($case)) {
                continue;
            }

            $outcome = $case['outcome'] ?? null;

            if (is_string($outcome) && (isset($seen[$outcome]) || $outcome === ($config['default'] ?? null))) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/cases/'.$index.'/outcome', params: ['keyword' => 'uniqueItems', 'expected' => 'unique outcomes']);
            }

            if (is_string($outcome)) {
                $seen[$outcome] = true;
            }

            $errors = [...$errors, ...ConditionValidator::validate($case['when'] ?? null, $scope, '/cases/'.$index.'/when')];
        }

        return $errors;
    }

    public function handle(StepContext $context): StepResult
    {
        $config = $context->config();

        foreach (is_array($config['cases'] ?? null) ? $config['cases'] : [] as $case) {
            if (is_array($case) && is_array($case['when'] ?? null) && is_string($case['outcome'] ?? null) && Evaluator::evaluate($case['when'], $context->scope())) {
                return StepResult::complete($case['outcome']);
            }
        }

        return StepResult::complete(is_string($config['default'] ?? null) ? $config['default'] : 'default');
    }

    public function simulate(StepContext $context): StepResult
    {
        return $this->handle($context);
    }
}
