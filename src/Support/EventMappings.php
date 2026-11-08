<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Workflows\DataTransferObjects\EventMapping;
use RoundlyConsulting\Workflows\Listeners\EmitMappedEvent;

/**
 * The Laravel events that emit: the `workflows.events` config merged with the mappings registered
 * in code (`Workflows::triggers()->map()` / `->listen()`). A class mapped again replaces its
 * earlier mapping (the last one wins) and is reported as a duplicate. Each class gets exactly one
 * listener, however often it is mapped. A singleton, so mappings made in a provider survive the
 * app being reset between Octane requests like the step catalogue does.
 *
 * @internal
 */
final class EventMappings
{
    /** @var array<string, EventMapping> */
    private array $code = [];

    /** @var array<string, true> */
    private array $listening = [];

    /** @var array<string, int> */
    private array $mapped = [];

    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function map(string $class, EventMapping $mapping): void
    {
        $this->mapped[$class] = ($this->mapped[$class] ?? 0) + 1;
        $this->code[$class] = $mapping;
        $this->wire($class);
    }

    /**
     * Listen for a class once (the config mappings are wired after every provider booted).
     */
    public function wire(string $class): void
    {
        if (isset($this->listening[$class])) {
            return;
        }

        $this->listening[$class] = true;
        $this->events->listen($class, [EmitMappedEvent::class, 'handle']);
    }

    /**
     * @return array<string, EventMapping>
     */
    public function all(): array
    {
        return [...WorkflowsConfig::events(), ...$this->code];
    }

    public function find(string $class): ?EventMapping
    {
        return $this->all()[$class] ?? null;
    }

    /**
     * Classes mapped more than once (in code twice, or in config and in code).
     *
     * @return list<string>
     */
    public function duplicates(): array
    {
        $configured = WorkflowsConfig::events();
        $duplicates = [];

        foreach ($this->mapped as $class => $count) {
            if ($count > 1 || isset($configured[$class])) {
                $duplicates[] = $class;
            }
        }

        return $duplicates;
    }
}
