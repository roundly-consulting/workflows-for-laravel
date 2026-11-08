<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;

/**
 * Fail-fast key-type checks: a model (at registration) or a key value (before every write) that
 * the configured morph column type cannot hold throws naming the config key and the model
 * basename, before any SQL runs.
 *
 * @internal
 */
final class KeyTypes
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function assertModel(string $role, string $model): void
    {
        $type = WorkflowsConfig::keyTypeFor($role);
        $instance = new $model;
        $traits = class_uses_recursive($model);

        $mismatch = match ($type) {
            KeyType::BigInt => $instance->getKeyType() !== 'int',
            KeyType::Uuid => $instance->getKeyType() === 'int' || in_array(HasUlids::class, $traits, true),
            KeyType::Ulid => $instance->getKeyType() === 'int' || in_array(HasUuids::class, $traits, true),
        };

        if ($mismatch) {
            throw InvalidWorkflowsConfigurationException::keyTypeMismatch(WorkflowsConfig::keyTypeKeyFor($role), class_basename($model), $type->value);
        }
    }

    public static function assertValue(string $role, ?Model $model): void
    {
        if ($model === null) {
            return;
        }

        $type = WorkflowsConfig::keyTypeFor($role);
        $key = $model->getKey();

        $valid = match ($type) {
            KeyType::BigInt => is_int($key) || (is_string($key) && ctype_digit($key)),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };

        if (! $valid) {
            throw InvalidWorkflowsConfigurationException::keyTypeMismatch(WorkflowsConfig::keyTypeKeyFor($role), class_basename($model), $type->value);
        }
    }
}
