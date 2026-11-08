<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\PackageToolkit\Exceptions\Concerns\DescribesConfigurationErrors;
use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;
use SensitiveParameter;

/**
 * A configuration value the package cannot use — every configuration error the package throws,
 * the toolkit readers' included. Operator-facing, so English, and always naming the config key
 * to fix. The toolkit's vocabulary (`missing()`, `outOfRange()`, `notAnImplementation()`, …)
 * comes from DescribesConfigurationErrors; the factories below are the package's own.
 */
final class InvalidWorkflowsConfigurationException extends PackageToolkitException
{
    use DescribesConfigurationErrors;

    public static function invalidDuration(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an ISO-8601 duration such as PT5M, [%s] given.', $key, self::describeConfigurationValue($value)));
    }

    public static function durationOutOfRange(string $key, string $range, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a duration %s, [%s] given.', $key, $range, self::describeConfigurationValue($value)));
    }

    public static function invalidTable(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a table name of lowercase letters, digits and underscores, at most 40 characters, [%s] given.', $key, self::describeConfigurationValue($value)));
    }

    public static function invalidMap(string $key, string $expected): self
    {
        return new self(sprintf('Configuration value [%s] must be %s.', $key, $expected));
    }

    public static function invalidTimezone(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an IANA timezone such as Europe/Bratislava, [%s] given.', $key, self::describeConfigurationValue($value)));
    }

    /**
     * A model whose key cannot be stored in the configured morph columns: caught before SQL, so
     * PostgreSQL never answers with a raw "invalid input syntax for type bigint".
     */
    public static function keyTypeMismatch(string $key, string $model, string $type): self
    {
        return new self(sprintf('The %s key does not match configuration value [%s] (%s). Set [%s] to the key type of %s.', $model, $key, $type, $key, $model));
    }
}
