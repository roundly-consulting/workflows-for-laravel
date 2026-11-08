<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schema;

/**
 * One JSON-schema violation: the JSON pointer within the value, the failed keyword, and what
 * was expected.
 *
 * @internal
 */
final readonly class SchemaError
{
    public function __construct(
        public string $path,
        public string $keyword,
        public string $expected,
    ) {}
}
