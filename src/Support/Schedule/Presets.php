<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schedule;

/**
 * Simple schedule presets compiled to cron: `{every: day|weekday|week|month|hour|minutes, at?:
 * "HH:MM", day?: 0-6 (week) | 1-31 (month), interval?: minutes}`.
 *
 * @internal
 */
final class Presets
{
    /**
     * @param  array<array-key, mixed>  $preset
     *
     * @throws InvalidCronException
     */
    public static function compile(array $preset): string
    {
        $every = $preset['every'] ?? null;
        $at = is_string($preset['at'] ?? null) ? $preset['at'] : '00:00';

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at, $time) !== 1) {
            throw new InvalidCronException($at);
        }

        $hour = (int) $time[1];
        $minute = (int) $time[2];
        $day = $preset['day'] ?? null;
        $interval = $preset['interval'] ?? null;

        return match ($every) {
            'minutes' => is_int($interval) && $interval >= 1 && $interval <= 59 ? '*/'.$interval.' * * * *' : throw new InvalidCronException('interval'),
            'hour' => $minute.' * * * *',
            'day' => "{$minute} {$hour} * * *",
            'weekday' => "{$minute} {$hour} * * 1-5",
            'week' => is_int($day) && $day >= 0 && $day <= 6 ? "{$minute} {$hour} * * {$day}" : "{$minute} {$hour} * * 1",
            'month' => is_int($day) && $day >= 1 && $day <= 31 ? "{$minute} {$hour} {$day} * *" : "{$minute} {$hour} 1 * *",
            default => throw new InvalidCronException(is_string($every) ? $every : 'every'),
        };
    }
}
