<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;
use Throwable;

/**
 * Reads a registered field off a model: its registered path (an attribute or a dotted relation),
 * normalised by type — enums as their backed value, dates in the version timezone (a date at
 * midnight keeps its stored calendar day), decimals as
 * exact strings, parties as models. An unregistered path is never read.
 *
 * @internal
 */
final class FieldReader
{
    public static function read(Model $model, FieldDefinition $field, string $timezone): mixed
    {
        $current = $model;
        $segments = explode('.', $field->path());
        $last = array_pop($segments);

        foreach ($segments as $segment) {
            $next = $current->getAttribute($segment);

            if (! $next instanceof Model) {
                return null;
            }

            $current = $next;
        }

        return self::normalize($current->getAttribute($last), $field->type, $timezone);
    }

    public static function normalize(mixed $value, FieldType $type, string $timezone): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum && $type !== FieldType::Party) {
            $value = $value->value;
        }

        return match ($type) {
            FieldType::Decimal => Decimals::normalize($value),
            FieldType::Integer => is_numeric($value) ? (int) $value : null,
            FieldType::Boolean => is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : null),
            FieldType::Date => self::calendarDate($value, $timezone),
            FieldType::Datetime => self::date($value, $timezone),
            FieldType::Party => $value instanceof Model ? $value : null,
            FieldType::List => $value instanceof Collection ? $value->values()->all() : (is_array($value) ? array_values($value) : null),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * A `date` field. Midnight — a DATE column, Laravel's `date` cast (midnight in the app
     * timezone), a bare `Y-m-d` — is the calendar day it was stored as, at midnight in the version
     * timezone: shifting that instant west would land on the previous day. A value with a time of
     * day is an instant, read in the version timezone like a datetime.
     */
    public static function calendarDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        $day = match (true) {
            $value instanceof DateTimeInterface => $value->format('H:i:s.u') === '00:00:00.000000' ? $value->format('Y-m-d') : null,
            is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ]00:00(?::00(?:\.0+)?)?)?$/', $value, $match) === 1 => $match[1],
            default => null,
        };

        if ($day === null) {
            return self::date($value, $timezone);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone);

        return $date instanceof CarbonImmutable ? $date : null;
    }

    public static function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        try {
            return match (true) {
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->setTimezone($timezone),
                is_string($value) && $value !== '' => CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
