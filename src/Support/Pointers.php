<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * RFC 6901 pointers of the leaves that differ between two JSON payloads: list order counts, map
 * order does not.
 *
 * @internal
 */
final class Pointers
{
    /**
     * @return list<string>
     */
    public static function diff(mixed $a, mixed $b, string $prefix = ''): array
    {
        if (is_array($a) && is_array($b) && ($a === [] || $b === [] || array_is_list($a) === array_is_list($b)) && ($a !== [] || $b !== [])) {
            $keys = array_keys($a + $b);

            if (! array_is_list($a + $b)) {
                sort($keys);
            }

            $changes = [];

            foreach ($keys as $key) {
                $path = $prefix.'/'.self::escape((string) $key);

                if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
                    $changes[] = $path;

                    continue;
                }

                $changes = [...$changes, ...self::diff($a[$key], $b[$key], $path)];
            }

            return $changes;
        }

        return $a === $b ? [] : [$prefix === '' ? '/' : $prefix];
    }

    public static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
