<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\ValidationScope;

/**
 * Escalation action handlers beyond the built-in `reassign` and `start_workflow`: bridges add
 * `notify`. Each validates its config at publish and runs with an idempotency key.
 *
 * @internal
 */
final class EscalationActions
{
    /** @var array<string, array{run: Closure(StepContext, array<string, mixed>, string): void, validate: Closure(array<string, mixed>, ValidationScope, string): list<ValidationError>}> */
    private array $actions = [];

    /**
     * @param  Closure(StepContext, array<string, mixed>, string): void  $run
     * @param  Closure(array<string, mixed>, ValidationScope, string): list<ValidationError>  $validate
     */
    public function register(string $type, Closure $run, Closure $validate): void
    {
        $this->actions[$type] = ['run' => $run, 'validate' => $validate];
    }

    public function has(string $type): bool
    {
        return isset($this->actions[$type]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function run(string $type, StepContext $context, array $config, string $key): void
    {
        ($this->actions[$type]['run'])($context, $config, $key);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<ValidationError>
     */
    public function validate(string $type, array $config, ValidationScope $scope, string $path): array
    {
        return ($this->actions[$type]['validate'])($config, $scope, $path);
    }
}
