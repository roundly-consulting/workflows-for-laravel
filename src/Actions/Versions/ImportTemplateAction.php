<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Versions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Definitions\CreateDefinitionAction;
use RoundlyConsulting\Workflows\DataTransferObjects\CreateDefinitionData;
use RoundlyConsulting\Workflows\DataTransferObjects\ImportResult;
use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;
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
        $template = $payload instanceof TemplatePayload ? $payload : self::parse($payload);

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

        ['graph' => $graph, 'map' => $map] = IdRemapper::remap($template->graph, $schemas);
        ['graph' => $graph, 'flags' => $flags] = $this->ownerSpecific->process($graph, clear: true, owner: $owner);

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
        $version->review_flags = $flags === [] ? null : array_map(static fn (ReviewFlag $flag): array => $flag->toArray(), $flags);
        $version->save();

        return new ImportResult($definition, $version, $flags, $map);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function parse(array $payload): TemplatePayload
    {
        $definition = $payload['definition'] ?? null;

        if (($payload['schema'] ?? null) !== TemplatePayload::SCHEMA || ! is_array($definition)
            || ! is_string($definition['key'] ?? null) || ! is_string($definition['name'] ?? null) || ! is_array($payload['graph'] ?? null)) {
            throw InvalidTemplateException::because('schema');
        }

        $subjectType = $definition['subject_type'] ?? null;
        $description = $definition['description'] ?? null;
        $checksum = is_array($payload['source'] ?? null) ? ($payload['source']['checksum'] ?? '') : '';

        return new TemplatePayload(
            key: $definition['key'],
            name: $definition['name'],
            subjectType: is_string($subjectType) ? $subjectType : null,
            description: is_string($description) ? $description : null,
            graph: GraphParser::stringKeyed($payload['graph']),
            exportedAt: is_string($payload['exported_at'] ?? null) ? $payload['exported_at'] : '',
            checksum: is_string($checksum) ? $checksum : '',
        );
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
