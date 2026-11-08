<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Authorization;
use RoundlyConsulting\Workflows\Support\Effects;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\PartyResolver;
use RoundlyConsulting\Workflows\Support\Values;
use RoundlyConsulting\Workflows\Support\ValueScope;

/**
 * Everything a handler reads: the instance, the step run, the node and its validated config, the
 * subject (loaded inside the owner context and checked against the owner), the owner, the actor,
 * the trigger input, earlier outputs, the idempotency key, the clock and business calendars.
 */
final readonly class StepContext
{
    /**
     * @internal
     */
    public function __construct(
        private WorkflowInstance $instance,
        private StepRun $stepRun,
        private Node $node,
        private Graph $graph,
        private WorkflowVersion $version,
        private Actor $actor,
        private ValueScope $scope,
        private ?Signal $signal = null,
        private bool $simulating = false,
        private ?string $definitionKey = null,
    ) {}

    public function instance(): WorkflowInstance
    {
        return $this->instance;
    }

    public function stepRun(): StepRun
    {
        return $this->stepRun;
    }

    public function node(): Node
    {
        return $this->node;
    }

    public function graph(): Graph
    {
        return $this->graph;
    }

    public function version(): WorkflowVersion
    {
        return $this->version;
    }

    public function definitionKey(): string
    {
        return $this->definitionKey ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->node->config;
    }

    public function subject(): ?Model
    {
        return $this->scope->subject;
    }

    public function owner(): ?Model
    {
        return $this->scope->owner;
    }

    public function actor(): Actor
    {
        return $this->actor;
    }

    /**
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->scope->input;
    }

    /**
     * @return array<string, mixed>
     */
    public function trigger(): array
    {
        return $this->scope->trigger;
    }

    /**
     * The output of the latest completed visit of a node.
     *
     * @return array<string, mixed>|null
     */
    public function output(string $nodeId): ?array
    {
        return ($this->scope->outputs)($nodeId);
    }

    /**
     * A literal, a `{"ref": …}` or a `{"relative": …}`, resolved.
     */
    public function resolve(mixed $value): mixed
    {
        return Values::resolve($value, $this->scope);
    }

    public function field(string $path): mixed
    {
        return Values::path($path, $this->scope);
    }

    /**
     * @param  list<PartyReference>  $parties
     * @return list<Model>
     */
    public function parties(array $parties): array
    {
        return app(PartyResolver::class)->resolve($parties, $this->scope);
    }

    /**
     * `wf:{instance ulid}:{node}:{visit}`: stable across every attempt of this visit.
     */
    public function idempotencyKey(): string
    {
        return $this->stepRun->idempotency_key;
    }

    public function attempt(): int
    {
        return $this->stepRun->attempt;
    }

    public function visit(): int
    {
        return $this->stepRun->visit;
    }

    public function now(): CarbonImmutable
    {
        return $this->scope->now;
    }

    public function timezone(): string
    {
        return $this->scope->timezone;
    }

    public function calendar(?string $name = null): BusinessCalendar
    {
        $default = $this->graph->setting('calendar');

        return app(CalendarRegistry::class)->find($name ?? (is_string($default) ? $default : 'weekdays'), $this->scope->owner, $this->scope->timezone);
    }

    public function defaultCalendar(): ?string
    {
        $default = $this->graph->setting('calendar');

        return is_string($default) ? $default : null;
    }

    /**
     * Authorize anything beyond the type's own ability. A denial fails the step cleanly.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function authorize(string $ability, array $arguments = []): void
    {
        if ($this->simulating) {
            return;
        }

        app(Authorization::class)->check($this->actor, $ability, $this->scope->subject, $this->node, $this->instance, $arguments);
    }

    /**
     * Run a database effect once per visit: the closure's result is recorded in the same
     * transaction, so a retry returns the stored result instead of running it again. Exactly-once
     * holds only for writes on the package's connection; pass the idempotency key on to anything
     * external. In a dry run nothing runs and null is returned.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|mixed
     */
    public function once(string $name, Closure $callback): mixed
    {
        if ($this->simulating) {
            return null;
        }

        return Effects::once($this->idempotencyKey(), $name, Keys::of($this->instance), $callback);
    }

    public function simulating(): bool
    {
        return $this->simulating;
    }

    public function signal(): ?Signal
    {
        return $this->signal;
    }

    /**
     * @internal
     */
    public function scope(): ValueScope
    {
        return $this->scope;
    }
}
