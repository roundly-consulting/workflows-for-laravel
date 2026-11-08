<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * "Did you mean …?": the registered key closest to a typo (Levenshtein distance ≤ 2, the
 * alphabetically first on a tie), or null when nothing is close.
 *
 * @internal
 */
final class Suggestions
{
    /**
     * @param  iterable<string>  $known
     */
    public static function closest(string $needle, iterable $known): ?string
    {
        $best = null;
        $distance = 3;

        foreach ($known as $candidate) {
            if ($candidate === $needle) {
                continue;
            }

            $current = levenshtein($needle, $candidate);

            if ($current < $distance || ($current === $distance && $best !== null && strcmp($candidate, $best) < 0)) {
                $best = $candidate;
                $distance = $current;
            }
        }

        return $best;
    }
}
