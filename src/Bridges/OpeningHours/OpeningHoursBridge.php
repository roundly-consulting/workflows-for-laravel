<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\OpeningHours;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Contracts\BusinessCalendar;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * opening-hours-for-laravel, when installed: `opening_hours:<name>` business calendars of the
 * definition owner (or of `business_time.calendar_owner` for app-wide definitions), holidays and
 * exceptions included.
 *
 * @internal
 */
final class OpeningHoursBridge
{
    public static function calendars(CalendarRegistry $registry): void
    {
        $registry->registerPrefix(
            'opening_hours',
            static function (string $name, ?Model $owner, string $timezone): BusinessCalendar {
                $owner = self::owner($owner);
                $manager = app(OpeningHoursManager::class);

                if (! $owner instanceof OpeningHoursOwner || ! $manager->has($owner, $name)) {
                    throw UnknownCalendarException::name('opening_hours:'.$name);
                }

                return new OpeningHoursCalendar($manager->for($owner, $name));
            },
            static function (string $name, ?Model $owner): bool {
                $owner = self::owner($owner);

                return $owner instanceof OpeningHoursOwner && app(OpeningHoursManager::class)->has($owner, $name);
            },
        );
    }

    /**
     * Whether an owner model can hold opening hours (it uses HasOpeningHours).
     */
    public static function isOwner(Model $owner): bool
    {
        return $owner instanceof OpeningHoursOwner;
    }

    private static function owner(?Model $owner): ?Model
    {
        if ($owner !== null) {
            return $owner;
        }

        $resolver = WorkflowsConfig::calendarOwnerResolver();

        if ($resolver === null || ! class_exists($resolver)) {
            return null;
        }

        $instance = app($resolver);
        $resolved = is_callable($instance) ? $instance() : null;

        return $resolved instanceof Model ? $resolved : null;
    }
}
