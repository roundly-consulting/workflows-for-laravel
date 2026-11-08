<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Definitions\ActivateDefinitionAction;
use RoundlyConsulting\Workflows\Actions\Definitions\DiscardDraftAction;
use RoundlyConsulting\Workflows\Actions\Definitions\PublishVersionAction;
use RoundlyConsulting\Workflows\Actions\Definitions\ResolveReviewFlagAction;
use RoundlyConsulting\Workflows\Actions\Definitions\RetireDefinitionAction;
use RoundlyConsulting\Workflows\Actions\Definitions\RotateWebhookAction;
use RoundlyConsulting\Workflows\Actions\Definitions\SaveDraftAction;
use RoundlyConsulting\Workflows\Actions\Versions\ExportTemplateAction;
use RoundlyConsulting\Workflows\Actions\Versions\ValidateGraphAction;
use RoundlyConsulting\Workflows\Builders\PendingDryRun;
use RoundlyConsulting\Workflows\Builders\PendingStart;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Exceptions\NothingToPublishException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Drafts;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * One definition of one owner: its draft, edits, validation, publishing, retirement, versions,
 * history, manual starts, dry runs and template export. Another owner's key throws
 * UnknownDefinitionException.
 */
final readonly class DefinitionHandle
{
    public function __construct(
        private WorkflowsManager $manager,
        private string $key,
        private ?Model $owner,
    ) {}

    /**
     * @throws UnknownDefinitionException
     */
    public function model(): WorkflowDefinition
    {
        return Definitions::find($this->key, $this->owner);
    }

    public function draft(): ?WorkflowVersion
    {
        return Drafts::find($this->model());
    }

    /**
     * Save a graph into the draft (created from the current version when there is none). Only
     * the structure is checked; `expectedChecksum` guards against a concurrent edit.
     *
     * @param  array<string, mixed>  $graph
     */
    public function edit(array $graph, ?string $expectedChecksum = null): ?WorkflowVersion
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::Edit,
            SaveDraftAction::class,
            static fn (SaveDraftAction $action): WorkflowVersion => $action->execute($definition, $graph, $expectedChecksum),
            ['definition' => $definition, 'key' => $this->key],
        );
    }

    public function discardDraft(): ?WorkflowDefinition
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::DiscardDraft,
            DiscardDraftAction::class,
            static fn (DiscardDraftAction $action): WorkflowDefinition => $action->execute($definition),
            ['definition' => $definition, 'key' => $this->key],
        );
    }

    /**
     * Full publish validation of the draft (what publish would refuse).
     */
    public function validate(): ValidationReport
    {
        $definition = $this->model();
        $draft = Drafts::find($definition) ?? throw NothingToPublishException::key($definition->key);

        return $this->manager->make(ValidateGraphAction::class)
            ->execute($draft->graph, $definition->subject_key, Definitions::owner($definition), $draft->review_flags ?? [], $definition->key);
    }

    public function publish(?Model $by = null): ?WorkflowVersion
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::Publish,
            PublishVersionAction::class,
            static fn (PublishVersionAction $action): WorkflowVersion => $action->execute($definition, $by),
            ['definition' => $definition, 'key' => $this->key, 'by' => $by],
        );
    }

    public function retire(): ?WorkflowDefinition
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::Retire,
            RetireDefinitionAction::class,
            static fn (RetireDefinitionAction $action): WorkflowDefinition => $action->execute($definition),
            ['definition' => $definition, 'key' => $this->key],
        );
    }

    public function activate(): ?WorkflowDefinition
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::Activate,
            ActivateDefinitionAction::class,
            static fn (ActivateDefinitionAction $action): WorkflowDefinition => $action->execute($definition),
            ['definition' => $definition, 'key' => $this->key],
        );
    }

    /**
     * Dismiss one import review flag on the draft.
     */
    public function resolveFlag(string $node, string $path): ?WorkflowVersion
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::ResolveFlag,
            ResolveReviewFlagAction::class,
            static fn (ResolveReviewFlagAction $action): WorkflowVersion => $action->execute($definition, $node, $path),
            ['definition' => $definition, 'key' => $this->key],
        );
    }

    public function rotateWebhook(): ?WorkflowDefinition
    {
        $definition = $this->model();

        return $this->manager->perform(
            WorkflowOperation::Edit,
            RotateWebhookAction::class,
            static fn (RotateWebhookAction $action): WorkflowDefinition => $action->execute($definition),
            ['definition' => $definition, 'key' => $this->key],
        );
    }

    /**
     * @return Collection<int, WorkflowVersion>
     */
    public function versions(): Collection
    {
        $model = WorkflowModels::version();

        return $model::query()->where('definition_id', Keys::of($this->model()))->orderBy('number')->get();
    }

    public function version(int $number): ?WorkflowVersion
    {
        $model = WorkflowModels::version();
        $version = $model::query()->where('definition_id', Keys::of($this->model()))->where('number', $number)->first();

        return $version instanceof WorkflowVersion ? $version : null;
    }

    /**
     * Run history, newest first.
     *
     * @return CursorPaginator<int, WorkflowInstance>
     */
    public function instances(?InstanceStatus $status = null, int $perPage = 25): CursorPaginator
    {
        $model = WorkflowModels::instance();

        return $model::query()
            ->where('definition_id', Keys::of($this->model()))
            ->when($status !== null, static fn ($query) => $query->where('status', $status?->value))
            ->with(['subject', 'owner', 'starter'])
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    public function start(?Model $subject = null): PendingStart
    {
        return $this->manager->start($this->key, $subject, $this->owner);
    }

    /**
     * A dry run of a version (the current one by default, else the draft).
     */
    public function dryRun(?int $version = null): PendingDryRun
    {
        $definition = $this->model();
        $target = $version === null ? ($definition->currentVersion ?? Drafts::find($definition)) : $this->version($version);

        if (! $target instanceof WorkflowVersion) {
            throw NothingToPublishException::key($definition->key);
        }

        return new PendingDryRun($this->manager, $definition, $target->graph, Keys::of($target));
    }

    public function export(?int $version = null): TemplatePayload
    {
        $definition = $this->model();
        $target = $version === null ? $definition->currentVersion : $this->version($version);

        if (! $target instanceof WorkflowVersion) {
            throw NothingToPublishException::key($definition->key);
        }

        return $this->manager->make(ExportTemplateAction::class)->execute($target);
    }
}
