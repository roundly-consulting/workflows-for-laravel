<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures;

use RoundlyConsulting\Workflows\Testing\InteractsWithWorkflows;

/**
 * Hosts the InteractsWithWorkflows helpers (and lets static analysis see the trait used).
 */
abstract class WorkflowsTestHelpers
{
    use InteractsWithWorkflows;
}
