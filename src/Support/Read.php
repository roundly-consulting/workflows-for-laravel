<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * Typed reads out of JSON payloads (node configs, outputs).
 *
 * @internal
 */
final class Read
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function string(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : $default);
    }
}
