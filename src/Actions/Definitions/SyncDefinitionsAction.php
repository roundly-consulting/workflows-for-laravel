<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use Illuminate\Database\Eloquent\Model;
use JsonException;
use RoundlyConsulting\Workflows\Actions\Versions\ValidateGraphAction;
use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\DataTransferObjects\SyncedDefinition;
use RoundlyConsulting\Workflows\DataTransferObjects\SyncReport;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\Enums\SyncStatus;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Graph\Canonicalizer;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Drafts;

/**
 * Keeps definitions in step with `*.json` files in the template envelope (what
 * `workflows:export` writes): a missing definition is created, a graph that differs from both the
 * current version and the draft is saved as the draft — and published when asked and valid — and
 * an unchanged file does nothing. Node ids are kept as written, so a file syncs the same way every
 * time and versions diff by node. Nothing is retired or deleted: definitions without a file are
 * reported as untracked. A dry run reports without writing.
 */
final readonly class SyncDefinitionsAction
{
    public function __construct(
        private CreateDefinitionAction $create,
        private SaveDraftAction $save,
        private PublishVersionAction $publish,
        private ValidateGraphAction $validate,
    ) {}

    public function execute(string $path, ?Model $owner = null, bool $publish = false, bool $dryRun = false): SyncReport
    {
        $files = is_dir($path) ? (glob(rtrim($path, '/').'/*.json') ?: []) : (is_file($path) ? [$path] : []);
        sort($files);

        $synced = [];
        $keys = [];

        foreach ($files as $file) {
            $result = $this->file($file, $owner, $publish, $dryRun);
            $synced[] = $result;

            if ($result->key !== null) {
                $keys[] = $result->key;
            }
        }

        $untracked = Definitions::query($owner)->whereNotIn('key', $keys)->orderBy('key')->pluck('key')->all();

        return new SyncReport($synced, array_values(array_filter($untracked, is_string(...))), $dryRun);
    }

    private function file(string $file, ?Model $owner, bool $publish, bool $dryRun): SyncedDefinition
    {
        $name = basename($file);

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $template = TemplatePayload::fromArray(is_array($decoded) ? GraphParser::stringKeyed($decoded) : []);
        } catch (JsonException|WorkflowsException $exception) {
            return new SyncedDefinition($name, null, SyncStatus::Invalid, message: $exception->getMessage());
        }

        try {
            return $this->definition($name, $template, $owner, $publish, $dryRun);
        } catch (InvalidGraphException $exception) {
            return new SyncedDefinition($name, $template->key, SyncStatus::Invalid, message: $exception->getMessage(), report: $exception->validationReport());
        } catch (WorkflowsException $exception) {
            return new SyncedDefinition($name, $template->key, SyncStatus::Failed, message: $exception->getMessage());
        }
    }

    private function definition(string $file, TemplatePayload $template, ?Model $owner, bool $publish, bool $dryRun): SyncedDefinition
    {
        $definition = Definitions::query($owner)->where('key', $template->key)->first();
        $checksum = Canonicalizer::checksum($template->graph);

        if (! $definition instanceof WorkflowDefinition) {
            if ($dryRun) {
                return $this->planned($file, $template, $owner, $publish, SyncStatus::Created);
            }

            $definition = $this->create->execute(new CreateDefinitionData($template->key, $template->name, $template->subjectType, $owner, $template->description));
            $this->save->execute($definition, $template->graph);

            return $publish
                ? $this->publish($file, $definition)
                : new SyncedDefinition($file, $template->key, SyncStatus::Created);
        }

        $current = $definition->currentVersion;
        $draft = Drafts::find($definition);

        if ($current instanceof WorkflowVersion && $current->checksum === $checksum) {
            return new SyncedDefinition($file, $template->key, SyncStatus::Unchanged, version: $current->number);
        }

        $overwrites = $draft instanceof WorkflowVersion && $draft->checksum !== $checksum && ($current === null || $draft->checksum !== $current->checksum);

        if ($dryRun) {
            return $this->planned($file, $template, $owner, $publish, $draft?->checksum === $checksum && ! $publish ? SyncStatus::Unchanged : SyncStatus::Drafted, $overwrites);
        }

        if ($draft?->checksum !== $checksum) {
            $this->save->execute($definition, $template->graph);
        } elseif (! $publish) {
            return new SyncedDefinition($file, $template->key, SyncStatus::Unchanged);
        }

        return $publish
            ? $this->publish($file, $definition, $overwrites)
            : new SyncedDefinition($file, $template->key, SyncStatus::Drafted, $overwrites);
    }

    private function publish(string $file, WorkflowDefinition $definition, bool $overwrites = false): SyncedDefinition
    {
        try {
            $version = $this->publish->execute($definition);
        } catch (InvalidGraphException $exception) {
            return new SyncedDefinition($file, $definition->key, SyncStatus::Invalid, $overwrites, message: $exception->getMessage(), report: $exception->validationReport());
        }

        return new SyncedDefinition($file, $definition->key, SyncStatus::Published, $overwrites, $version->number);
    }

    /**
     * What a dry run reports: the would-be status, and `invalid` with the report when publishing
     * would be refused.
     */
    private function planned(string $file, TemplatePayload $template, ?Model $owner, bool $publish, SyncStatus $status, bool $overwrites = false): SyncedDefinition
    {
        if (! $publish) {
            return new SyncedDefinition($file, $template->key, $status, $overwrites);
        }

        $report = $this->validate->execute($template->graph, $template->subjectType, $owner, [], $template->key);

        return $report->valid()
            ? new SyncedDefinition($file, $template->key, SyncStatus::Published, $overwrites)
            : new SyncedDefinition($file, $template->key, SyncStatus::Invalid, $overwrites, message: InvalidGraphException::report($report)->getMessage(), report: $report);
    }
}
