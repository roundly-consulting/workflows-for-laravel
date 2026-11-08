<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use RoundlyConsulting\Workflows\Support\Schema\GraphSchema;
use RoundlyConsulting\Workflows\Support\Schema\SharedDefinitions;

/**
 * The versioned JSON Schemas a builder UI validates against: the graph document, the template
 * payload, and the shared `$defs` step config schemas reference.
 */
final readonly class SchemaAccessor
{
    /**
     * @return array<string, mixed>
     */
    public function graph(): array
    {
        return GraphSchema::graph();
    }

    /**
     * @return array<string, mixed>
     */
    public function template(): array
    {
        return GraphSchema::template();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return SharedDefinitions::all();
    }
}
