<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * Exact decimal comparison on strings: no floats, no exponents. `"50000.00"` equals `50000`;
 * `"1e5"` is not a decimal.
 *
 * @internal
 */
final class Decimals
{
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            return null;
        }

        $sign = $matches[1] === '-' ? '-' : '';
        $integer = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');
        $integer = $integer === '' ? '0' : $integer;

        if ($integer === '0' && $fraction === '') {
            return '0';
        }

        return $sign.$integer.($fraction === '' ? '' : '.'.$fraction);
    }

    public static function isDecimal(mixed $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * -1, 0 or 1. Null when either side is not a decimal.
     */
    public static function compare(mixed $a, mixed $b): ?int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === null || $b === null) {
            return null;
        }

        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');

        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        $result = self::compareAbsolute(ltrim($a, '-'), ltrim($b, '-'));

        return $negativeA ? -$result : $result;
    }

    private static function compareAbsolute(string $a, string $b): int
    {
        [$intA, $fracA] = array_pad(explode('.', $a, 2), 2, '');
        [$intB, $fracB] = array_pad(explode('.', $b, 2), 2, '');

        if (strlen($intA) !== strlen($intB)) {
            return strlen($intA) <=> strlen($intB);
        }

        $integer = strcmp($intA, $intB);

        if ($integer !== 0) {
            return $integer <=> 0;
        }

        $length = max(strlen($fracA), strlen($fracB));

        return strcmp(str_pad($fracA, $length, '0'), str_pad($fracB, $length, '0')) <=> 0;
    }
}
