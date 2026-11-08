<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Traits\HasWorkflows;

/**
 * The smallest subject: lets static analysis see HasWorkflows used.
 */
final class WorkflowsSubject extends Model
{
    use HasWorkflows;

    protected $guarded = [];
}
