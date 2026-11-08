<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * A value carried through an API whose return type depends on it (keeps the generic intact).
 *
 * @internal
 *
 * @template T
 */
final readonly class Boxed
{
    /**
     * @param  T  $value
     */
    public function __construct(
        public mixed $value,
    ) {}
}
