<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use RoundlyConsulting\Workflows\Bridges\Notifications\NotificationsBridge;
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

    private bool $loaded = false;

    /**
     * @param  Closure(StepContext, array<string, mixed>, string): void  $run
     * @param  Closure(array<string, mixed>, ValidationScope, string): list<ValidationError>  $validate
     */
    public function register(string $type, Closure $run, Closure $validate): void
    {
        $this->load();

        $this->actions[$type] = ['run' => $run, 'validate' => $validate];
    }

    public function has(string $type): bool
    {
        $this->load();

        return isset($this->actions[$type]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function run(string $type, StepContext $context, array $config, string $key): void
    {
        $this->load();

        ($this->actions[$type]['run'])($context, $config, $key);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<ValidationError>
     */
    public function validate(string $type, array $config, ValidationScope $scope, string $path): array
    {
        $this->load();

        return ($this->actions[$type]['validate'])($config, $scope, $path);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (Bridges::available('notifications')) {
            NotificationsBridge::escalationActions($this);
        }
    }
}
