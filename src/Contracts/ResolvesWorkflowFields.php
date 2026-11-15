<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;

/**
 * A subject (or owner) model that reads its own dynamic fields — the ones its type's
 * `dynamicFields()` resolver adds per owner (custom fields kept in a JSON column or a separate
 * table, say). Only dynamic fields are read through it; registered fields are read by their path.
 * The value is normalised by the field's type, as a path read is.
 */
interface ResolvesWorkflowFields
{
    public function resolveWorkflowField(FieldDefinition $field): mixed;
}
