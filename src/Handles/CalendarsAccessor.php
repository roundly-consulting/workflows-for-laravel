<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Business calendars: the built-in `weekdays`, opening-hours calendars (`opening_hours:<name>`,
 * when that package is installed) and the host's own.
 */
final readonly class CalendarsAccessor
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * @param  Closure(?Model, string): BusinessCalendar  $resolver  receives the owner and the version timezone
     */
    public function register(string $name, Closure $resolver): self
    {
        $this->registry()->register($name, $resolver);

        return $this;
    }

    public function find(string $name, ?Model $owner = null, ?string $timezone = null): BusinessCalendar
    {
        return $this->registry()->find($name, $owner, $timezone ?? WorkflowsConfig::timezone());
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->registry()->names();
    }

    private function registry(): CalendarRegistry
    {
        return $this->container->make(CalendarRegistry::class);
    }
}
