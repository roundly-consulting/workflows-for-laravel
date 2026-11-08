<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Versions\DiffVersionsAction;
use RoundlyConsulting\Workflows\Actions\Versions\ExportTemplateAction;
use RoundlyConsulting\Workflows\Actions\Versions\ImportTemplateAction;
use RoundlyConsulting\Workflows\Actions\Versions\OverlayAction;
use RoundlyConsulting\Workflows\Actions\Versions\ValidateGraphAction;
use RoundlyConsulting\Workflows\Builders\PendingDryRun;
use RoundlyConsulting\Workflows\DataTransferObjects\ImportResult;
use RoundlyConsulting\Workflows\DataTransferObjects\OverlayReport;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\DataTransferObjects\VersionDiff;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Exceptions\InvalidTemplateException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * What a builder UI needs about graphs and versions: validation, diff, the run overlay, dry runs,
 * template export and import.
 */
final readonly class VersionsAccessor
{
    public function __construct(
        private WorkflowsManager $manager,
    ) {}

    /**
     * @param  array<string, mixed>  $graph
     */
    public function validate(array $graph, ?string $subjectType = null, ?Model $owner = null): ValidationReport
    {
        return $this->manager->make(ValidateGraphAction::class)->execute($graph, $subjectType, $owner);
    }

    public function diff(WorkflowVersion $from, WorkflowVersion $to): VersionDiff
    {
        return $this->manager->make(DiffVersionsAction::class)->execute($from, $to);
    }

    public function overlay(WorkflowVersion $version): OverlayReport
    {
        return $this->manager->make(OverlayAction::class)->execute($version);
    }

    public function export(WorkflowVersion $version): TemplatePayload
    {
        return $this->manager->make(ExportTemplateAction::class)->execute($version);
    }

    /**
     * @param  TemplatePayload|array<string, mixed>  $payload
     */
    public function import(TemplatePayload|array $payload, ?Model $owner = null, ?string $key = null): ?ImportResult
    {
        return $this->manager->perform(
            WorkflowOperation::Import,
            ImportTemplateAction::class,
            static fn (ImportTemplateAction $action): ImportResult => $action->execute($payload, $owner, $key),
            ['owner' => $owner, 'key' => $key],
        );
    }

    /**
     * A dry run of a version, or of any graph against a definition (its subject type and owner).
     *
     * @param  WorkflowVersion|array<string, mixed>  $graph
     */
    public function dryRun(WorkflowVersion|array $graph, ?WorkflowDefinition $definition = null): PendingDryRun
    {
        if ($graph instanceof WorkflowVersion) {
            $owner = $graph->definition;

            return new PendingDryRun($this->manager, $owner instanceof WorkflowDefinition ? $owner : throw InvalidTemplateException::because('definition'), $graph->graph, Keys::of($graph));
        }

        return new PendingDryRun($this->manager, $definition ?? throw InvalidTemplateException::because('definition'), $graph);
    }
}
