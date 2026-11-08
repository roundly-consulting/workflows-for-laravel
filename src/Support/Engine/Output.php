<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Handler output must be plain JSON (scalars, lists, maps; no models, objects, closures, INF or
 * NAN) and fit `steps_output.max_bytes`.
 *
 * @internal
 */
final class Output
{
    /**
     * @param  array<array-key, mixed>  $output
     */
    public static function valid(array $output): bool
    {
        if (! self::plain($output, 0)) {
            return false;
        }

        $encoded = json_encode($output, JSON_PRESERVE_ZERO_FRACTION);

        return is_string($encoded) && strlen($encoded) <= WorkflowsConfig::stepOutputMaxBytes();
    }

    private static function plain(mixed $value, int $depth): bool
    {
        if ($depth > 32) {
            return false;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::plain($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
