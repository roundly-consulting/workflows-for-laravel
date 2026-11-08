<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Versions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Definitions\CreateDefinitionAction;
use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\DataTransferObjects\ImportResult;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\Exceptions\InvalidTemplateException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Graph\IdRemapper;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Drafts;
use RoundlyConsulting\Workflows\Support\OwnerSpecific;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;

/**
 * Imports a template as a new draft definition for an owner: every node id is remapped (and every
 * reference rewritten), owner-specific values (users, calendars, start targets, endpoints) are
 * cleared and flagged, and publish is blocked until each flag is resolved. The key gets a `-2`,
 * `-3`, … suffix when taken.
 */
final readonly class ImportTemplateAction
{
    public function __construct(
        private CreateDefinitionAction $create,
        private SubjectRegistry $subjects,
        private StepCatalogue $catalogue,
        private OwnerSpecific $ownerSpecific,
    ) {}

    /**
     * @param  TemplatePayload|array<string, mixed>  $payload
     */
    public function execute(TemplatePayload|array $payload, ?Model $owner = null, ?string $key = null): ImportResult
    {
        $template = $payload instanceof TemplatePayload ? $payload : TemplatePayload::fromArray($payload);

        if ($template->subjectType !== null && $this->subjects->find($template->subjectType) === null) {
            throw InvalidTemplateException::because('subject_type');
        }

        if (GraphParser::structure($template->graph) !== []) {
            throw InvalidTemplateException::because('graph');
        }

        $schemas = [];

        foreach ($this->catalogue->all() as $typeKey => $type) {
            $schemas[$typeKey] = $type->configSchema();
        }

        $remapped = IdRemapper::remap($template->graph, $schemas);
        $map = $remapped->map;
        $cleared = $this->ownerSpecific->process($remapped->graph, clear: true, owner: $owner);
        $graph = $cleared->graph;
        $flags = $cleared->flags;

        $definition = $this->create->execute(new CreateDefinitionData(
            key: $this->freeKey($key ?? $template->key, $owner),
            name: $template->name,
            subjectType: $template->subjectType,
            owner: $owner,
            description: $template->description,
        ));

        $version = Drafts::find($definition);

        if (! $version instanceof WorkflowVersion) {
            throw InvalidTemplateException::because('draft');
        }

        Drafts::fill($version, $graph);
        $version->setReviewFlags($flags);
        $version->save();

        return new ImportResult($definition, $version, $flags, $map);
    }

    private function freeKey(string $key, ?Model $owner): string
    {
        $candidate = $key;
        $suffix = 1;

        while (Definitions::query($owner, withTrashed: true)->where('key', $candidate)->exists()) {
            $suffix++;
            $candidate = substr($key, 0, 95).'-'.$suffix;
        }

        return $candidate;
    }
}
