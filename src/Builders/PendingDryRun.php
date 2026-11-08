<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\DryRun\DryRunAction;
use RoundlyConsulting\Workflows\DataTransferObjects\DryRunData;
use RoundlyConsulting\Workflows\DataTransferObjects\DryRunResult;
use RoundlyConsulting\Workflows\DataTransferObjects\DryRunTree;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * A dry run being built: `->subject($model)` or `->sample([...])`, `->input()`, `->event()`,
 * `->assume(['approve' => 'rejected'])`, `->at($instant)`, `->log()`, then `->run()` (one path)
 * or `->explore()` (every branch).
 */
final class PendingDryRun
{
    private ?Model $subject = null;

    /** @var array<string, mixed>|null */
    private ?array $sample = null;

    /** @var array<string, mixed> */
    private array $input = [];

    private ?string $event = null;

    /** @var array<string, mixed> */
    private array $payload = [];

    /** @var array<string, string> */
    private array $assume = [];

    private ?CarbonInterface $at = null;

    private ?bool $log = null;

    private ?Model $requester = null;

    /**
     * @param  array<string, mixed>  $graph
     */
    public function __construct(
        private readonly WorkflowsManager $manager,
        private readonly WorkflowDefinition $definition,
        private readonly array $graph,
        private readonly int|string|null $versionId = null,
    ) {}

    public function subject(Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * An unsaved subject built from registered fields only.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function sample(array $attributes): self
    {
        $this->sample = $attributes;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function input(array $input): self
    {
        $this->input = $input;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function event(string $key, array $payload = []): self
    {
        $this->event = $key;
        $this->payload = $payload;

        return $this;
    }

    /**
     * The outcome to assume for side-effect or waiting nodes (node id => outcome).
     *
     * @param  array<string, string>  $outcomes
     */
    public function assume(array $outcomes): self
    {
        $this->assume = $outcomes;

        return $this;
    }

    public function at(CarbonInterface $instant): self
    {
        $this->at = $instant;

        return $this;
    }

    public function log(bool $log = true): self
    {
        $this->log = $log;

        return $this;
    }

    public function by(?Model $requester): self
    {
        $this->requester = $requester;

        return $this;
    }

    /**
     * Walk every way the graph can go: wherever an outcome had to be assumed (a side effect, a
     * wait), branch into each outcome the step can end with. Bounded by `graph.max_nodes` walks;
     * the log option is ignored (nothing is logged). `run()` stays the single path.
     */
    public function explore(): DryRunTree
    {
        $budget = WorkflowsConfig::graphMaxNodes();

        return $this->branch($this->assume, $budget);
    }

    public function run(): DryRunResult
    {
        return $this->walk($this->assume, $this->log);
    }

    /**
     * @param  array<string, string>  $assume
     */
    private function branch(array $assume, int &$budget): DryRunTree
    {
        $budget--;
        $result = $this->walk($assume, false);
        $graph = GraphParser::parse($this->graph);
        $catalogue = $this->manager->make(StepCatalogue::class);

        foreach ($result->path as $step) {
            $node = $graph->node($step->node);
            $type = $node === null ? null : $catalogue->find($node->type);

            if (! $step->assumed || isset($assume[$step->node]) || $node === null || $type === null) {
                continue;
            }

            $outcomes = array_map(static fn (Outcome $outcome): string => $outcome->key, $type->outcomes($node->config));

            if (count($outcomes) < 2 || $budget < count($outcomes)) {
                continue;
            }

            $branches = [];

            foreach ($outcomes as $outcome) {
                $branches[$outcome] = $this->branch([...$assume, $step->node => $outcome], $budget);
            }

            return new DryRunTree($result, $step->node, $branches);
        }

        return new DryRunTree($result);
    }

    /**
     * @param  array<string, string>  $assume
     */
    private function walk(array $assume, ?bool $log): DryRunResult
    {
        $data = new DryRunData(
            definition: $this->definition,
            graph: $this->graph,
            versionId: $this->versionId,
            subject: $this->subject,
            sample: $this->sample,
            input: $this->input,
            event: $this->event,
            payload: $this->payload,
            assume: $assume,
            at: $this->at,
            log: $log,
            requester: $this->requester,
        );

        return $this->manager->perform(
            WorkflowOperation::DryRun,
            DryRunAction::class,
            static fn (DryRunAction $action): DryRunResult => $action->execute($data),
            static fn (): DryRunResult => DryRunResult::empty(),
            ['data' => $data],
        );
    }
}
