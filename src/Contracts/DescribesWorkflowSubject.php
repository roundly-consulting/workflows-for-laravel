<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use RoundlyConsulting\Workflows\Builders\SubjectTypeBuilder;

/**
 * A subject type registered through `config('workflows.subjects')`: the class returns the
 * builder that describes it (`Workflows::subject('purchase_request', PurchaseRequest::class)->…`).
 */
interface DescribesWorkflowSubject
{
    public function describe(): SubjectTypeBuilder;
}
