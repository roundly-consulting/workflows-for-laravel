<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * Translated package lines (`workflows::<file>.<key>`), always returned as a string.
 *
 * @internal
 */
final class Messages
{
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = __('workflows::'.$key, array_map(static fn (string|int|float $value): string => (string) $value, $replace));

        return is_string($line) ? $line : $key;
    }
}
