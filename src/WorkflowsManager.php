<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RoundlyConsulting\Workflows\Actions\Instances\EmitEventAction;
use RoundlyConsulting\Workflows\Actions\Instances\SignalAction;
use RoundlyConsulting\Workflows\Actions\Instances\StartInstanceAction;
use RoundlyConsulting\Workflows\Actions\Maintenance\PruneAction;
use RoundlyConsulting\Workflows\Actions\Wake\WakeAction;
use RoundlyConsulting\Workflows\Builders\PendingStart;
use RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitData;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitResult;
use RoundlyConsulting\Workflows\DataTransferObjects\PruneReport;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\DataTransferObjects\WakeReport;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Graph\Build\GraphBuilder;
use RoundlyConsulting\Workflows\Handles\CalendarsAccessor;
use RoundlyConsulting\Workflows\Handles\CatalogueAccessor;
use RoundlyConsulting\Workflows\Handles\DefinitionHandle;
use RoundlyConsulting\Workflows\Handles\DefinitionsAccessor;
use RoundlyConsulting\Workflows\Handles\InstancesAccessor;
use RoundlyConsulting\Workflows\Handles\SchemaAccessor;
use RoundlyConsulting\Workflows\Handles\SubjectHandle;
use RoundlyConsulting\Workflows\Handles\SubjectsAccessor;
use RoundlyConsulting\Workflows\Handles\TriggersAccessor;
use RoundlyConsulting\Workflows\Handles\VersionsAccessor;
use RoundlyConsulting\Workflows\Steps\Builtin\TriggerStep;

/**
 * The package's public API: the root behind the Workflows facade, injectable by its class-string.
 * Every method is thin — it resolves an action through the container (so host overrides and the
 * fake apply) or returns a small handle / sub-accessor that does. Holds no request state.
 *
 * Not final: WorkflowsFake extends it, so an injected manager still type-checks under the fake.
 */
class WorkflowsManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * Start a definition with a `manual` trigger: `->input()`, `->by()`, `->idempotencyKey()`,
     * then `->dispatch()`.
     */
    public function start(string $key, ?Model $subject = null, ?Model $owner = null): PendingStart
    {
        return new PendingStart($this, $key, $subject, $owner);
    }

    public function startWith(StartData $data): StartResult
    {
        return $this->perform(
            WorkflowOperation::Start,
            StartInstanceAction::class,
            static fn (StartInstanceAction $action): StartResult => $action->execute($data),
            static fn (): StartResult => new StartResult(null, false, skipped: 'faked', definition: $data->key),
            ['data' => $data, 'key' => $data->key],
        );
    }

    /**
     * An app event: starts every matching event-triggered definition of the subject's owner and
     * signals the waits listening for it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $event, ?Model $subject = null, array $payload = [], ?string $idempotencyKey = null, ?Model $user = null, ?Model $owner = null): EmitResult
    {
        return $this->emitWith(new EmitData($event, $subject, $payload, $idempotencyKey, $user, $owner));
    }

    public function emitWith(EmitData $data): EmitResult
    {
        // Before perform(): the fake refuses the keys the real emit refuses.
        TriggerStep::assertEventKey($data->event);

        return $this->perform(
            WorkflowOperation::Emit,
            EmitEventAction::class,
            static fn (EmitEventAction $action): EmitResult => $action->execute($data),
            static fn (): EmitResult => new EmitResult,
            ['data' => $data, 'event' => $data->event],
        );
    }

    /**
     * Everything about one subject: its instances, starts and emits about it, and `touch()`.
     */
    public function for(Model $subject): SubjectHandle
    {
        return new SubjectHandle($this, $subject);
    }

    /**
     * One definition of one owner (null = app-wide). Another owner's key is never found.
     */
    public function definition(string $key, ?Model $owner = null): DefinitionHandle
    {
        return new DefinitionHandle($this, $key, $owner);
    }

    public function definitions(): DefinitionsAccessor
    {
        return new DefinitionsAccessor($this);
    }

    public function versions(): VersionsAccessor
    {
        return new VersionsAccessor($this);
    }

    public function instances(): InstancesAccessor
    {
        return new InstancesAccessor($this);
    }

    public function catalogue(): CatalogueAccessor
    {
        return new CatalogueAccessor($this->container);
    }

    public function subjects(): SubjectsAccessor
    {
        return new SubjectsAccessor($this->container);
    }

    /**
     * Describe a subject type (register it with `subjects()->register()`).
     *
     * @param  class-string<Model>  $model
     */
    public function subject(string $key, string $model): SubjectTypeBuilder
    {
        return new SubjectTypeBuilder($key, $model);
    }

    public function calendars(): CalendarsAccessor
    {
        return new CalendarsAccessor($this->container);
    }

    public function triggers(): TriggersAccessor
    {
        return new TriggersAccessor($this);
    }

    public function schema(): SchemaAccessor
    {
        return new SchemaAccessor;
    }

    /**
     * A fluent graph builder: `Workflows::graph()->trigger(...)->approval(...)->connect(...)`.
     * Pure — it builds a document and runs nothing — so the fake passes it through.
     */
    public function graph(): GraphBuilder
    {
        return new GraphBuilder;
    }

    /**
     * Deliver an internal signal key to the waits listening for it (bridges use it).
     *
     * @internal
     *
     * @param  array<string, mixed>  $payload
     */
    public function signal(string $key, ?Model $subject = null, array $payload = []): int
    {
        return $this->perform(
            WorkflowOperation::Signal,
            SignalAction::class,
            static fn (SignalAction $action): int => $action->execute($key, $subject, $payload),
            static fn (): int => 0,
            ['key' => $key, 'subject' => $subject],
        );
    }

    /**
     * One `workflows:wake` pass: due timers, retries, timeouts, polls, escalations, schedules.
     */
    public function wake(?CarbonInterface $now = null): WakeReport
    {
        return $this->perform(
            WorkflowOperation::Wake,
            WakeAction::class,
            static fn (WakeAction $action): WakeReport => $action->execute($now),
            static fn (): WakeReport => new WakeReport,
            ['now' => $now],
        );
    }

    public function prune(): PruneReport
    {
        return $this->perform(
            WorkflowOperation::Prune,
            PruneAction::class,
            static fn (PruneAction $action): PruneReport => $action->execute(),
            static fn (): PruneReport => new PruneReport,
        );
    }

    /**
     * The one path every mutating call takes: resolve the action from the container and run it.
     * The fake overrides this to record the call and return the stand-in (or, in passthrough,
     * still run it); the real manager never calls the stand-in.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  Closure(): TResult  $standIn
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(WorkflowOperation $operation, string $action, Closure $execute, Closure $standIn, array $context = []): mixed
    {
        $instance = $this->container->make($action);

        if (! $instance instanceof $action) {
            throw new LogicException(sprintf('The container resolved [%s] to another class.', class_basename($action)));
        }

        return $execute($instance);
    }

    /**
     * Resolve a read-only action (validation, diff, overlay, export, history).
     *
     * @internal
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function make(string $class): object
    {
        $instance = $this->container->make($class);

        return $instance instanceof $class ? $instance : throw new LogicException(sprintf('The container resolved [%s] to another class.', class_basename($class)));
    }
}
