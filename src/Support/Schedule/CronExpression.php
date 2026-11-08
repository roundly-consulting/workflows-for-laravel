<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schedule;

use Carbon\CarbonImmutable;

/**
 * A native five-field cron subset (minute, hour, day of month, month, day of week): `*`, `a`,
 * `a-b`, `*\/n`, `a-b/n` and lists; `JAN`–`DEC` and `SUN`–`SAT`; `0` and `7` are Sunday; the
 * `@hourly`/`@daily`/`@weekly`/`@monthly`/`@yearly` macros. Vixie semantics: when both day fields
 * are restricted, either may match. `L`, `W`, `#`, `?`, seconds and years are refused.
 *
 * Times are local wall-clock times: pass instants already in the schedule's timezone.
 *
 * @internal
 */
final readonly class CronExpression
{
    private const array MACROS = [
        '@hourly' => '0 * * * *',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@weekly' => '0 0 * * 0',
        '@monthly' => '0 0 1 * *',
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
    ];

    private const array MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];

    private const array DAYS = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];

    /**
     * @param  list<int>  $minutes
     * @param  list<int>  $hours
     * @param  list<int>  $days
     * @param  list<int>  $months
     * @param  list<int>  $weekdays
     */
    private function __construct(
        public string $expression,
        public array $minutes,
        public array $hours,
        public array $days,
        public array $months,
        public array $weekdays,
        public bool $daysRestricted,
        public bool $weekdaysRestricted,
    ) {}

    /**
     * @throws InvalidCronException
     */
    public static function parse(string $expression): self
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $expression) ?? $expression);
        $normalized = self::MACROS[strtolower($normalized)] ?? $normalized;

        if (str_starts_with($normalized, '@')) {
            throw new InvalidCronException($normalized);
        }

        $fields = explode(' ', $normalized);

        if (count($fields) !== 5) {
            throw new InvalidCronException($normalized);
        }

        $weekdays = self::field($fields[4], 0, 7, self::DAYS);
        $weekdays = array_values(array_unique(array_map(static fn (int $day): int => $day === 7 ? 0 : $day, $weekdays)));
        sort($weekdays);

        return new self(
            $expression,
            self::field($fields[0], 0, 59),
            self::field($fields[1], 0, 23),
            self::field($fields[2], 1, 31),
            self::field($fields[3], 1, 12, self::MONTHS),
            $weekdays,
            $fields[2] !== '*',
            $fields[4] !== '*',
        );
    }

    public static function isValid(string $expression): bool
    {
        try {
            self::parse($expression);
        } catch (InvalidCronException) {
            return false;
        }

        return true;
    }

    public function matches(CarbonImmutable $local): bool
    {
        return in_array($local->minute, $this->minutes, true)
            && in_array($local->hour, $this->hours, true)
            && in_array($local->month, $this->months, true)
            && $this->matchesDay($local);
    }

    /**
     * The first matching minute strictly after `$after` (local), within `$maxDays`. A field-set
     * walk (month → day → hour → minute), never a minute-by-minute scan.
     */
    public function nextAfter(CarbonImmutable $after, int $maxDays = 366): ?CarbonImmutable
    {
        $limit = $after->addDays($maxDays);
        $cursor = $after->startOfMinute()->addMinute();

        while ($cursor->lessThanOrEqualTo($limit)) {
            if (! in_array($cursor->month, $this->months, true)) {
                $cursor = $cursor->startOfMonth()->addMonthNoOverflow()->startOfMonth();

                continue;
            }

            if (! $this->matchesDay($cursor)) {
                $cursor = $cursor->startOfDay()->addDay();

                continue;
            }

            if (! in_array($cursor->hour, $this->hours, true)) {
                $next = $this->nextIn($this->hours, $cursor->hour);
                $cursor = $next === null ? $cursor->startOfDay()->addDay() : $cursor->setTime($next, 0);

                continue;
            }

            if (! in_array($cursor->minute, $this->minutes, true)) {
                $next = $this->nextIn($this->minutes, $cursor->minute);
                $cursor = $next === null ? $cursor->startOfHour()->addHour() : $cursor->setTime($cursor->hour, $next);

                continue;
            }

            return $cursor;
        }

        return null;
    }

    /**
     * The last matching minute at or before `$before` (local) and after `$since`.
     */
    public function previousBefore(CarbonImmutable $before, CarbonImmutable $since): ?CarbonImmutable
    {
        $found = null;
        $cursor = $since;

        while (($next = $this->nextAfter($cursor, max(1, (int) ceil($cursor->diffInDays($before, true)) + 1))) !== null && $next->lessThanOrEqualTo($before)) {
            $found = $next;
            $cursor = $next;
        }

        return $found;
    }

    private function matchesDay(CarbonImmutable $local): bool
    {
        $day = in_array($local->day, $this->days, true);
        $weekday = in_array($local->dayOfWeek, $this->weekdays, true);

        if ($this->daysRestricted && $this->weekdaysRestricted) {
            return $day || $weekday;
        }

        return $day && $weekday;
    }

    /**
     * @param  list<int>  $values
     */
    private function nextIn(array $values, int $current): ?int
    {
        foreach ($values as $value) {
            if ($value > $current) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $names
     * @return list<int>
     *
     * @throws InvalidCronException
     */
    private static function field(string $field, int $min, int $max, array $names = []): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            if ($part === '' || preg_match('/[LW#?]/i', $part) === 1 && preg_match('/^(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC|SUN|MON|TUE|WED|THU|FRI|SAT)/i', $part) !== 1) {
                throw new InvalidCronException($part === '' ? $field : $part);
            }

            [$range, $step] = array_pad(explode('/', $part, 2), 2, null);
            $step = $step === null ? 1 : self::number($step, $part);

            if ($step < 1) {
                throw new InvalidCronException($part);
            }

            if ($range === '*') {
                [$low, $high] = [$min, $max];
            } elseif (str_contains((string) $range, '-')) {
                [$a, $b] = explode('-', (string) $range, 2);
                [$low, $high] = [self::value($a, $names, $part), self::value($b, $names, $part)];
            } else {
                $low = self::value((string) $range, $names, $part);
                $high = $step > 1 ? $max : $low;
            }

            if ($low < $min || $high > $max || $low > $high) {
                throw new InvalidCronException($part);
            }

            for ($value = $low; $value <= $high; $value += $step) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param  array<string, int>  $names
     *
     * @throws InvalidCronException
     */
    private static function value(string $token, array $names, string $part): int
    {
        $upper = strtoupper($token);

        if (isset($names[$upper])) {
            return $names[$upper];
        }

        return self::number($token, $part);
    }

    /**
     * @throws InvalidCronException
     */
    private static function number(string $token, string $part): int
    {
        if (preg_match('/^\d{1,2}$/', $token) !== 1) {
            throw new InvalidCronException($part);
        }

        return (int) $token;
    }
}
