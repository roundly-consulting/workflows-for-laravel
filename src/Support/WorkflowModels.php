<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowDryRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;

/**
 * The model seam: the only place that reads `workflows.models.*`. A host swaps a model by
 * extending the packaged class and naming the subclass in config.
 *
 * @internal
 */
final class WorkflowModels
{
    /** @return class-string<WorkflowDefinition> */
    public static function definition(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.definition', WorkflowDefinition::class));
    }

    /** @return class-string<WorkflowVersion> */
    public static function version(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.version', WorkflowVersion::class));
    }

    /** @return class-string<WorkflowInstance> */
    public static function instance(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.instance', WorkflowInstance::class));
    }

    /** @return class-string<StepRun> */
    public static function stepRun(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.step_run', StepRun::class));
    }

    /** @return class-string<WorkflowWait> */
    public static function wait(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.wait', WorkflowWait::class));
    }

    /** @return class-string<WorkflowEscalation> */
    public static function escalation(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.escalation', WorkflowEscalation::class));
    }

    /** @return class-string<WorkflowIdempotencyKey> */
    public static function idempotencyKey(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.idempotency_key', WorkflowIdempotencyKey::class));
    }

    /** @return class-string<WorkflowDryRun> */
    public static function dryRun(): string
    {
        return WorkflowsConfig::rethrown(static fn (): string => ModelResolver::for('workflows.models.dry_run', WorkflowDryRun::class));
    }
}
