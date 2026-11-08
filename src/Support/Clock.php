<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The package's one notion of "now" and of a stored instant: UTC, `Y-m-d H:i:s`, whole seconds.
 *
 * @internal
 */
final class Clock
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    public static function format(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format(self::FORMAT);
    }

    public static function utc(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->utc()->startOfSecond();
    }
}
