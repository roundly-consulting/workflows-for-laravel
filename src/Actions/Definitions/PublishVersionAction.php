<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Actions\Versions\ValidateGraphAction;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Events\VersionPublished;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Exceptions\NothingToPublishException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Drafts;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\KeyTypes;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * Publishes the draft under the definition row lock: full validation (refused with the report),
 * then the draft becomes the immutable current version, the previous one is superseded, and the
 * definition is active. Running instances stay on the version they started on.
 */
final readonly class PublishVersionAction
{
    public function __construct(
        private ValidateGraphAction $validate,
    ) {}

    public function execute(WorkflowDefinition $definition, ?Model $by = null): WorkflowVersion
    {
        KeyTypes::assertValue('user', $by);

        $model = WorkflowModels::definition();
        $connection = (new $model)->getConnection();

        [$locked, $draft] = $connection->transaction(function () use ($definition, $model, $by): array {
            $locked = $model::query()->whereKey(Keys::of($definition))->lockForUpdate()->firstOrFail();
            $draft = Drafts::find($locked);

            if ($draft === null) {
                throw NothingToPublishException::key($locked->key);
            }

            $report = $this->validate->execute($draft->graph, $locked->subject_key, Definitions::owner($locked), $draft->reviewFlags(), $locked->key);

            if (! $report->valid()) {
                throw InvalidGraphException::report($report);
            }

            $now = Clock::now();
            $previous = $locked->currentVersion;

            if ($previous instanceof WorkflowVersion) {
                $previous->status = VersionStatus::Superseded;
                $previous->save();
            }

            $draft->status = VersionStatus::Published;
            $draft->published_at = $now;
            $draft->publisher_type = $by?->getMorphClass();
            $draft->publisher_id = Keys::nullable($by);
            $draft->schedule_cursor_at = $now;
            $draft->save();

            $locked->current_version_id = Keys::of($draft);
            $locked->status = DefinitionStatus::Active;

            if ($draft->trigger_type === TriggerType::Webhook && $locked->webhook_id === null) {
                $locked->webhook_id = (string) Str::ulid();
            }

            $locked->save();

            Events::dispatch(new VersionPublished($locked, $draft, $by));

            return [$locked, $draft];
        });

        $definition->setRawAttributes($locked->getAttributes(), true);

        return $draft;
    }
}
