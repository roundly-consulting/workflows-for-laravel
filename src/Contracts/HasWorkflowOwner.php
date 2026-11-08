<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject that names its owner (tenant) directly. Checked first when resolving a subject's
 * owner; null means "no owner".
 */
interface HasWorkflowOwner
{
    public function workflowOwner(): ?Model;
}
