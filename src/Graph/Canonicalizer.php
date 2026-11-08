<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph;

use RoundlyConsulting\Crypto\Facades\Crypto;

/**
 * The canonical form of a JSON payload (map keys sorted recursively, list order kept) and its
 * SHA-256 checksum: two graphs with the same content have the same checksum whatever their key
 * order.
 *
 * @internal
 */
final class Canonicalizer
{
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::canonical(...), $value);
    }

    public static function encode(mixed $value): string
    {
        return (string) json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function checksum(mixed $value): string
    {
        return Crypto::digest()->hex(self::encode($value));
    }

    /**
     * Whether two payloads are equal by content (map order ignored, list order significant).
     */
    public static function equal(mixed $a, mixed $b): bool
    {
        return self::encode($a) === self::encode($b);
    }
}
