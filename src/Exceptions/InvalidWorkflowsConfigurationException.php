<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;
use SensitiveParameter;

/**
 * A configuration value the package cannot use. Operator-facing, so English, and always naming
 * the config key to fix. (The toolkit's InvalidConfigurationException is final, so this extends
 * its base, like notifications and events.)
 */
final class InvalidWorkflowsConfigurationException extends PackageToolkitException
{
    public static function invalidDuration(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an ISO-8601 duration such as PT5M, [%s] given.', $key, self::describe($value)));
    }

    public static function durationOutOfRange(string $key, string $range, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a duration %s, [%s] given.', $key, $range, self::describe($value)));
    }

    public static function invalidTable(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a table name of lowercase letters, digits and underscores, at most 40 characters, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidMap(string $key, string $expected): self
    {
        return new self(sprintf('Configuration value [%s] must be %s.', $key, $expected));
    }

    public static function notAnImplementation(string $key, string $contract, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a class-string of [%s], [%s] given.', $key, class_basename($contract), self::describe($value)));
    }

    public static function invalidTimezone(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an IANA timezone such as Europe/Bratislava, [%s] given.', $key, self::describe($value)));
    }

    /**
     * A model whose key cannot be stored in the configured morph columns: caught before SQL, so
     * PostgreSQL never answers with a raw "invalid input syntax for type bigint".
     */
    public static function keyTypeMismatch(string $key, string $model, string $type): self
    {
        return new self(sprintf('The %s key does not match configuration value [%s] (%s). Set [%s] to the key type of %s.', $model, $key, $type, $key, $model));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_object($value) => class_basename($value),
            default => get_debug_type($value),
        };
    }
}
