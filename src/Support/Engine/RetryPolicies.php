<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use RoundlyConsulting\Workflows\DataTransferObjects\RetryPolicy;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Resolves a node's retry policy: the node's `retry` over the graph's `settings.retry` over the
 * type's `retry()` over `workflows.retry.default`, capped by `retry.max_attempts_cap`.
 *
 * @internal
 */
final class RetryPolicies
{
    public static function resolve(Node $node, Graph $graph, ?StepType $type): RetryPolicy
    {
        $cap = WorkflowsConfig::maxAttemptsCap();
        $policy = WorkflowsConfig::retryDefault();

        if ($type?->retry() !== null) {
            $policy = $type->retry();
        }

        $policy = RetryPolicy::overlay($graph->setting('retry'), $policy, $cap) ?? $policy;
        $policy = RetryPolicy::overlay($node->retry, $policy, $cap) ?? $policy;

        return $policy->withCap($cap);
    }

    /**
     * Seconds before the given attempt, jitter included.
     */
    public static function delay(RetryPolicy $policy, int $attempt): int
    {
        $base = $policy->delayBefore($attempt);

        if ($policy->jitter <= 0 || $base === 0) {
            return $base;
        }

        return $base + (int) floor($base * $policy->jitter * (mt_rand() / mt_getrandmax()));
    }
}
