<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Versions;

use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\Exceptions\InvalidTemplateException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\OwnerSpecific;

/**
 * Exports a version as a portable template: no owner, no source ids, no class names (the subject
 * type is its registry key). Owner-specific values are kept and listed under `review`.
 */
final readonly class ExportTemplateAction
{
    public function __construct(
        private OwnerSpecific $ownerSpecific,
    ) {}

    public function execute(WorkflowVersion $version): TemplatePayload
    {
        $definition = $version->definition;

        if (! $definition instanceof WorkflowDefinition) {
            throw InvalidTemplateException::because('definition');
        }

        $scanned = $this->ownerSpecific->process($version->graph, clear: false, owner: Definitions::owner($definition));

        return new TemplatePayload(
            key: $definition->key,
            name: $definition->name,
            subjectType: $definition->subject_key,
            description: $definition->description,
            graph: $version->graph,
            exportedAt: Clock::now()->toIso8601String(),
            checksum: $version->checksum,
            review: $scanned->flags,
        );
    }
}
