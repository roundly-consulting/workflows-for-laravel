<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * Everything the package holds for one owner, for a GDPR or company export
 * (`roundly.workflows.owner-export/v1`): its definitions with every version as a template, and a
 * summary of every run. Deleted rows the package still stores are included and dated. No
 * secrets, step inputs or outputs, trigger payloads or class names.
 */
final readonly class OwnerExport
{
    public const string SCHEMA = 'roundly.workflows.owner-export/v1';

    /**
     * @param  array{type: string, id: int|string}  $owner
     * @param  list<ExportedDefinition>  $definitions
     * @param  list<RunSummary>  $runs
     */
    public function __construct(
        public array $owner,
        public string $exportedAt,
        public array $definitions,
        public array $runs,
    ) {}

    /**
     * Every exported version as a template, in definition and version order — each one imports
     * with `Workflows::versions()->import()`.
     *
     * @return list<TemplatePayload>
     */
    public function templates(): array
    {
        $templates = [];

        foreach ($this->definitions as $definition) {
            foreach ($definition->versions as $version) {
                $templates[] = $version->template;
            }
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'owner' => $this->owner,
            'exported_at' => $this->exportedAt,
            'definitions' => array_map(static fn (ExportedDefinition $definition): array => $definition->toArray(), $this->definitions),
            'runs' => array_map(static fn (RunSummary $run): array => $run->toArray(), $this->runs),
        ];
    }
}
