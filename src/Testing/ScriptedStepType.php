<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Closure;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\ValidationScope;

/**
 * A step type whose handler is scripted (`fakeStep()`): it wears the original's key, label,
 * schema, outcomes, side effects, ability and escalation support — so validation, authorization,
 * waits and escalations run for real — and only `handle()` returns the scripted result. A type
 * that is not registered (a bridged one whose package is not installed) takes the outcomes the
 * test declares, accepts any config and has no side effects.
 *
 * @internal
 */
final class ScriptedStepType extends StepType
{
    /** @var list<StepContext> */
    private array $runs = [];

    /**
     * @param  StepResult|string|Closure(StepContext): StepResult  $result  a string is an outcome
     * @param  list<string>  $outcomes  for an unregistered type
     */
    public function __construct(
        private readonly string $type,
        private readonly StepResult|string|Closure $result,
        private readonly ?StepType $original = null,
        private readonly array $outcomes = ['next'],
    ) {}

    public function key(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->original?->label() ?? $this->type;
    }

    public function description(): ?string
    {
        return $this->original?->description();
    }

    public function category(): string
    {
        return $this->original?->category() ?? 'custom';
    }

    public function configSchema(): array
    {
        return $this->original?->configSchema() ?? ['type' => 'object', 'additionalProperties' => true];
    }

    public function outcomes(array $config): array
    {
        return $this->original?->outcomes($config) ?? array_map(static fn (string $outcome): Outcome => Outcome::of($outcome), $this->outcomes);
    }

    public function hasSideEffects(): bool
    {
        return $this->original?->hasSideEffects() ?? false;
    }

    public function ability(): ?string
    {
        return $this->original?->ability();
    }

    public function abilityFor(StepContext $context): ?string
    {
        return $this->original?->abilityFor($context);
    }

    public function supportsEscalations(): bool
    {
        return $this->original?->supportsEscalations() ?? true;
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        return $this->original?->validateConfig($config, $scope) ?? [];
    }

    public function ownerSpecificPaths(): array
    {
        return $this->original?->ownerSpecificPaths() ?? [];
    }

    public function handle(StepContext $context): StepResult
    {
        $this->runs[] = $context;

        return match (true) {
            $this->result instanceof StepResult => $this->result,
            is_string($this->result) => StepResult::complete($this->result),
            default => ($this->result)($context),
        };
    }

    public function original(): ?StepType
    {
        return $this->original;
    }

    /**
     * @return list<StepContext>
     */
    public function runs(): array
    {
        return $this->runs;
    }
}
