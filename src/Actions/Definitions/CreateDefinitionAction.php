<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Definitions;

use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Exceptions\DefinitionKeyTakenException;
use RoundlyConsulting\Workflows\Exceptions\InvalidDefinitionException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Drafts;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\KeyTypes;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * A new draft definition with version 1 holding the minimal graph (manual trigger → end). The
 * key is unique per owner — a soft-deleted definition still holds it (restore that one instead).
 */
final readonly class CreateDefinitionAction
{
    public const string KEY = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    public function __construct(
        private SubjectRegistry $subjects,
    ) {}

    public function execute(CreateDefinitionData $data): WorkflowDefinition
    {
        if (preg_match(self::KEY, $data->key) !== 1) {
            throw InvalidDefinitionException::key($data->key);
        }

        if ($data->subjectType !== null) {
            $this->subjects->get($data->subjectType);
        }

        KeyTypes::assertValue('owner', $data->owner);

        $model = WorkflowModels::definition();
        $connection = (new $model)->getConnection();

        return $connection->transaction(function () use ($data, $model): WorkflowDefinition {
            $existing = Definitions::query($data->owner, withTrashed: true)->where('key', $data->key)->lockForUpdate()->first();

            if ($existing instanceof WorkflowDefinition) {
                throw DefinitionKeyTakenException::key($data->key, $existing->trashed());
            }

            $definition = new $model;
            $definition->key = $data->key;
            $definition->name = $data->name;
            $definition->description = $data->description;
            $definition->owner_type = $data->owner?->getMorphClass();
            $definition->owner_id = Keys::nullable($data->owner);
            $definition->owner_scope = Owners::scope($data->owner);
            $definition->subject_key = $data->subjectType;
            $definition->status = DefinitionStatus::Draft;
            $definition->save();

            $versionModel = WorkflowModels::version();
            $version = new $versionModel;
            $version->definition_id = Keys::of($definition);
            $version->number = 1;
            $version->status = VersionStatus::Draft;
            Drafts::fill($version, GraphParser::minimal());
            $version->save();

            return $definition;
        });
    }
}
