<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;

/**
 * What a dry run walks: a definition's graph (a version's, or any graph), against a subject or a
 * sample payload, with trigger input / an event payload, assumed outcomes for side-effect steps,
 * a virtual start time, and whether to log the result.
 */
final readonly class DryRunData
{
    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>|null  $sample
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $assume
     */
    public function __construct(
        public WorkflowDefinition $definition,
        public array $graph,
        public int|string|null $versionId = null,
        public ?Model $subject = null,
        public ?array $sample = null,
        public array $input = [],
        public ?string $event = null,
        public array $payload = [],
        public array $assume = [],
        public ?CarbonInterface $at = null,
        public ?bool $log = null,
        public ?Model $requester = null,
    ) {}
}
