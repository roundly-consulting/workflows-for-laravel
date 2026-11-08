<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;

/**
 * Resolves graph values: a literal, `{"ref": "<path>"}` or `{"relative": <duration>}`. Paths:
 * `subject.<field>`, `owner.<field>`, `input.<name>`, `trigger.<key>`,
 * `steps.<node>.output.<key>`, `now`. Only registered fields are read.
 *
 * @internal
 */
final class Values
{
    public static function isRef(mixed $value): bool
    {
        return is_array($value) && count($value) === 1 && is_string($value['ref'] ?? null);
    }

    public static function isRelative(mixed $value): bool
    {
        return is_array($value) && count($value) === 1 && array_key_exists('relative', $value);
    }

    public static function resolve(mixed $value, ValueScope $scope): mixed
    {
        if (self::isRef($value)) {
            /** @var array{ref: string} $value */
            return self::path($value['ref'], $scope);
        }

        if (self::isRelative($value)) {
            $duration = Durations::parse(is_array($value) ? $value['relative'] : null);

            return $duration === null ? null : Durations::add($duration, $scope->now, $scope->timezone, null, $scope->owner)->setTimezone($scope->timezone);
        }

        return $value;
    }

    /**
     * A map of value refs, resolved key by key.
     *
     * @param  array<array-key, mixed>  $map
     * @return array<string, mixed>
     */
    public static function resolveMap(array $map, ValueScope $scope): array
    {
        $resolved = [];

        foreach ($map as $key => $value) {
            $resolved[(string) $key] = self::resolve($value, $scope);
        }

        return $resolved;
    }

    public static function path(string $path, ValueScope $scope): mixed
    {
        if ($path === 'now') {
            return $scope->now->setTimezone($scope->timezone);
        }

        [$root, $rest] = array_pad(explode('.', $path, 2), 2, '');

        return match ($root) {
            'subject' => self::field($scope->subject !== null && $scope->subjectType !== null ? [$scope->subject, $scope->subjectType] : null, $rest, $scope),
            'owner' => self::field($scope->owner !== null && $scope->ownerType !== null ? [$scope->owner, $scope->ownerType] : null, $rest, $scope),
            'input' => $rest === '' ? null : Arr::get($scope->input, $rest),
            'trigger' => $rest === '' ? null : Arr::get($scope->trigger, $rest),
            'steps' => self::step($rest, $scope),
            default => null,
        };
    }

    /**
     * The declared type at a path, when it is known (registered fields, declared inputs, now).
     */
    public static function typeOf(string $path, ValueScope $scope): ?FieldType
    {
        if ($path === 'now') {
            return FieldType::Datetime;
        }

        [$root, $rest] = array_pad(explode('.', $path, 2), 2, '');

        return match ($root) {
            'subject' => $scope->subjectType?->field($rest)?->type,
            'owner' => $scope->ownerType?->field($rest)?->type,
            'input' => $scope->inputTypes[$rest] ?? null,
            default => null,
        };
    }

    /**
     * @param  array{0: Model, 1: SubjectTypeDefinition}|null  $target
     */
    private static function field(?array $target, string $name, ValueScope $scope): mixed
    {
        if ($target === null || $name === '') {
            return null;
        }

        $field = $target[1]->field($name);

        return $field === null ? null : FieldReader::read($target[0], $field, $scope->timezone);
    }

    private static function step(string $rest, ValueScope $scope): mixed
    {
        $parts = explode('.', $rest, 3);

        if (count($parts) < 2 || $parts[1] !== 'output') {
            return null;
        }

        $output = ($scope->outputs)($parts[0]);

        if ($output === null) {
            return null;
        }

        return isset($parts[2]) ? Arr::get($output, $parts[2]) : $output;
    }
}
