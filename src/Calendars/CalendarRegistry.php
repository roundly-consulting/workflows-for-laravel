<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Calendars;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Bridges\OpeningHours\OpeningHoursBridge;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * The business calendars a graph may name: the built-in `weekdays`, host calendars registered by
 * name, and prefix resolvers bridges add (`opening_hours:<name>`). Holds no request state.
 */
final class CalendarRegistry
{
    /** @var array<string, Closure(?Model, string): BusinessCalendar> */
    private array $calendars = [];

    /** @var array<string, array{resolve: Closure(string, ?Model, string): ?BusinessCalendar, exists: Closure(string, ?Model): bool}> */
    private array $prefixes = [];

    private bool $loaded = false;

    /**
     * @param  Closure(?Model, string): BusinessCalendar  $resolver  receives the owner and the version timezone
     */
    public function register(string $name, Closure $resolver): self
    {
        $this->calendars[$name] = $resolver;

        return $this;
    }

    /**
     * @internal
     *
     * @param  Closure(string, ?Model, string): ?BusinessCalendar  $resolve
     * @param  Closure(string, ?Model): bool  $exists
     */
    public function registerPrefix(string $prefix, Closure $resolve, Closure $exists): self
    {
        $this->load();

        $this->prefixes[$prefix] = ['resolve' => $resolve, 'exists' => $exists];

        return $this;
    }

    /**
     * @throws UnknownCalendarException
     */
    public function find(string $name, ?Model $owner, string $timezone): BusinessCalendar
    {
        $this->load();

        if ($name === 'weekdays') {
            return new WeekdayCalendar($timezone, WorkflowsConfig::businessWeekdays(), WorkflowsConfig::businessMaxSearchDays());
        }

        if (isset($this->calendars[$name])) {
            return ($this->calendars[$name])($owner, $timezone);
        }

        foreach ($this->prefixes as $prefix => $resolver) {
            if (str_starts_with($name, $prefix.':')) {
                $calendar = ($resolver['resolve'])(substr($name, strlen($prefix) + 1), $owner, $timezone);

                if ($calendar !== null) {
                    return $calendar;
                }
            }
        }

        throw UnknownCalendarException::name($name);
    }

    /**
     * Whether a calendar of that name can be resolved for the owner (publish validation).
     */
    public function has(string $name, ?Model $owner): bool
    {
        $this->load();

        if ($name === 'weekdays' || isset($this->calendars[$name])) {
            return true;
        }

        foreach ($this->prefixes as $prefix => $resolver) {
            if (str_starts_with($name, $prefix.':')) {
                return ($resolver['exists'])(substr($name, strlen($prefix) + 1), $owner);
            }
        }

        return false;
    }

    /**
     * Whether the name is owner-specific (cleared on template import).
     */
    public function isOwnerSpecific(string $name): bool
    {
        $this->load();

        foreach (array_keys($this->prefixes) as $prefix) {
            if (str_starts_with($name, $prefix.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $this->load();

        return ['weekdays', ...array_keys($this->calendars), ...array_map(static fn (string $prefix): string => $prefix.':*', array_keys($this->prefixes))];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (Bridges::available('opening-hours')) {
            OpeningHoursBridge::calendars($this);
        }
    }
}
