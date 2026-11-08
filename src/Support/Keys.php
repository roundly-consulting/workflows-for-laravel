<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A model's key as `int|string` (Eloquent types it `mixed`).
 *
 * @internal
 */
final class Keys
{
    public static function of(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new LogicException(sprintf('A %s without a key.', class_basename($model)));
    }

    public static function nullable(?Model $model): int|string|null
    {
        return $model === null ? null : self::of($model);
    }

    public static function string(Model $model): string
    {
        return (string) self::of($model);
    }
}
