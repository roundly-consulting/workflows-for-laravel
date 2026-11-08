<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Workflows\Bridges\Approvals\ApprovalStep;
use RoundlyConsulting\Workflows\Bridges\Notifications\NotifyStep;
use RoundlyConsulting\Workflows\Exceptions\DuplicateStepTypeException;
use RoundlyConsulting\Workflows\Exceptions\InvalidStepTypeException;
use RoundlyConsulting\Workflows\Exceptions\UnknownStepTypeException;
use RoundlyConsulting\Workflows\Steps\Builtin\BranchStep;
use RoundlyConsulting\Workflows\Steps\Builtin\ConditionStep;
use RoundlyConsulting\Workflows\Steps\Builtin\DelayStep;
use RoundlyConsulting\Workflows\Steps\Builtin\EndStep;
use RoundlyConsulting\Workflows\Steps\Builtin\LoopStep;
use RoundlyConsulting\Workflows\Steps\Builtin\SetFieldStep;
use RoundlyConsulting\Workflows\Steps\Builtin\StartWorkflowStep;
use RoundlyConsulting\Workflows\Steps\Builtin\TransitionStep;
use RoundlyConsulting\Workflows\Steps\Builtin\TriggerStep;
use RoundlyConsulting\Workflows\Steps\Builtin\WaitStep;
use RoundlyConsulting\Workflows\Steps\Builtin\WebhookStep;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Schema\SchemaValidator;

/**
 * The step catalogue: the built-ins, bridge types and host types, keyed by type key. Resolved
 * lazily (the config list is read on first use), so host types registered in the app's own
 * providers — which boot after the package's — are visible. Holds no request state.
 *
 * @internal
 */
final class StepCatalogue
{
    /**
     * Built-in keys a host type can never take.
     */
    public const array RESERVED = ['trigger', 'condition', 'branch', 'set_field', 'transition', 'delay', 'wait', 'loop', 'start_workflow', 'end', 'approval', 'notify', 'webhook'];

    /**
     * The package a bridge type comes with: a graph using one without it reports `adapter_missing`.
     */
    public const array ADAPTERS = [
        'approval' => 'roundly-consulting/approvals-for-laravel',
        'notify' => 'roundly-consulting/notifications-for-laravel',
        'transition' => 'roundly-consulting/lifecycle-for-laravel',
        'webhook' => 'roundly-consulting/webhooks-for-laravel',
    ];

    /** @var array<string, StepType> */
    private array $types = [];

    private bool $loaded = false;

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register a host step type (idempotent for the same class).
     *
     * @param  class-string<StepType>|StepType  $type
     *
     * @throws InvalidStepTypeException|DuplicateStepTypeException
     */
    public function register(string|StepType $type): StepType
    {
        $this->load();

        return $this->add($type, builtin: false);
    }

    /**
     * Register a package or bridge type under a reserved key.
     *
     * @internal
     *
     * @param  class-string<StepType>|StepType  $type
     */
    public function registerBuiltin(string|StepType $type): StepType
    {
        $this->load();

        return $this->add($type, builtin: true);
    }

    public function find(string $key): ?StepType
    {
        $this->load();

        return $this->types[$key] ?? null;
    }

    /**
     * @throws UnknownStepTypeException
     */
    public function get(string $key): StepType
    {
        return $this->find($key) ?? throw UnknownStepTypeException::key($key, array_keys($this->types), $this->missingAdapter($key));
    }

    /**
     * Put a type in place of the one under its key (or of none) and hand back the undo — the
     * testing helpers' step fakes use it.
     *
     * @internal
     *
     * @return Closure(): void
     */
    public function swap(string $key, StepType $type): Closure
    {
        $this->load();
        $original = $this->types[$key] ?? null;
        $this->types[$key] = $type;

        return function () use ($key, $original): void {
            if ($original === null) {
                unset($this->types[$key]);
            } else {
                $this->types[$key] = $original;
            }
        };
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * @return array<string, StepType>
     */
    public function all(): array
    {
        $this->load();

        return $this->types;
    }

    /**
     * The package a key needs when the type is missing or its adapter is not available.
     */
    public function missingAdapter(string $key): ?string
    {
        $type = $this->find($key);

        if ($type === null) {
            return self::ADAPTERS[$key] ?? null;
        }

        if ($type instanceof TransitionStep && ! $type->available()) {
            return self::ADAPTERS['transition'];
        }

        return null;
    }

    /**
     * @param  class-string<StepType>|StepType  $type
     */
    private function add(string|StepType $type, bool $builtin): StepType
    {
        if (is_string($type)) {
            if (! class_exists($type) || ! is_subclass_of($type, StepType::class)) {
                throw InvalidStepTypeException::notAStepType($type);
            }

            $instance = $this->container->make($type);
            $type = $instance instanceof StepType ? $instance : throw InvalidStepTypeException::notAStepType($type);
        }

        $key = $type->key();

        if (! $builtin && (in_array($key, self::RESERVED, true) || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key) !== 1 || strlen($key) > 64)) {
            throw InvalidStepTypeException::key($key);
        }

        if (isset($this->types[$key])) {
            if ($type::class === $this->types[$key]::class) {
                return $this->types[$key];
            }

            throw DuplicateStepTypeException::key($key);
        }

        SchemaValidator::assertSupported($type->configSchema(), $key);

        $this->types[$key] = $type;

        return $type;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach ([TriggerStep::class, ConditionStep::class, BranchStep::class, SetFieldStep::class, TransitionStep::class,
            DelayStep::class, WaitStep::class, LoopStep::class, StartWorkflowStep::class, EndStep::class] as $builtin) {
            $this->add($builtin, builtin: true);
        }

        if (Bridges::available('approvals')) {
            $this->add(ApprovalStep::class, builtin: true);
        }

        if (Bridges::available('notifications')) {
            $this->add(NotifyStep::class, builtin: true);
        }

        if (Adapters::webhooks() !== null) {
            $this->add(WebhookStep::class, builtin: true);
        }

        foreach (WorkflowsConfig::steps() as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, StepType::class)) {
                throw InvalidStepTypeException::notAStepType($class);
            }

            $this->add($class, builtin: false);
        }
    }
}
