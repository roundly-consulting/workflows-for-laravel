<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * A step type that cannot be registered: a key outside the naming rules, a schema keyword the
 * validator does not support, a remote `$ref`, or an invalid `pattern`. Thrown at registration,
 * so a broken host type never reaches publish.
 */
final class InvalidStepTypeException extends WorkflowsException
{
    public static function key(string $key): self
    {
        return self::make('invalid_step_key', 500, ['key' => $key]);
    }

    public static function keyword(string $key, string $keyword, string $path): self
    {
        return self::make('unsupported_schema_keyword', 500, ['key' => $key, 'keyword' => $keyword, 'path' => $path]);
    }

    public static function pattern(string $key, string $path): self
    {
        return self::make('invalid_schema_pattern', 500, ['key' => $key, 'path' => $path]);
    }

    public static function notAStepType(string $class): self
    {
        return self::make('not_a_step_type', 500, ['class' => class_basename($class)]);
    }
}
