<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * A new definition: its key (unique per owner), name, subject type (registry key, or null for a
 * subject-less workflow), owner (null = app-wide) and description.
 */
final readonly class CreateDefinitionData
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $subjectType = null,
        public ?Model $owner = null,
        public ?string $description = null,
    ) {}
}
