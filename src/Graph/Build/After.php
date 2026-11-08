<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

use RoundlyConsulting\Workflows\Enums\DurationUnit;

/**
 * A duration in a graph: `{"amount", "unit", "calendar"?}` (business units count open time in a
 * calendar), or an ISO-8601 string (wall-clock time).
 */
final readonly class After
{
    /**
     * @param  array<string, int|string>|string  $value
     */
    private function __construct(
        private array|string $value,
    ) {}

    public static function minutes(int $amount): self
    {
        return self::of($amount, DurationUnit::Minutes);
    }

    public static function hours(int $amount): self
    {
        return self::of($amount, DurationUnit::Hours);
    }

    public static function days(int $amount): self
    {
        return self::of($amount, DurationUnit::Days);
    }

    public static function weeks(int $amount): self
    {
        return self::of($amount, DurationUnit::Weeks);
    }

    public static function months(int $amount): self
    {
        return self::of($amount, DurationUnit::Months);
    }

    public static function businessMinutes(int $amount, ?string $calendar = null): self
    {
        return self::of($amount, DurationUnit::BusinessMinutes, $calendar);
    }

    public static function businessHours(int $amount, ?string $calendar = null): self
    {
        return self::of($amount, DurationUnit::BusinessHours, $calendar);
    }

    public static function businessDays(int $amount, ?string $calendar = null): self
    {
        return self::of($amount, DurationUnit::BusinessDays, $calendar);
    }

    /**
     * An ISO-8601 duration such as `P2D` or `PT30M`.
     */
    public static function iso(string $duration): self
    {
        return new self($duration);
    }

    /**
     * @return array<string, int|string>|string
     */
    public function toValue(): array|string
    {
        return $this->value;
    }

    private static function of(int $amount, DurationUnit $unit, ?string $calendar = null): self
    {
        return new self($calendar === null
            ? ['amount' => $amount, 'unit' => $unit->value]
            : ['amount' => $amount, 'unit' => $unit->value, 'calendar' => $calendar]);
    }
}
