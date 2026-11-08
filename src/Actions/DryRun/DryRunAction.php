<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\DryRun;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Actions\Versions\ValidateGraphAction;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\DryRunData;
use RoundlyConsulting\Workflows\DataTransferObjects\DryRunResult;
use RoundlyConsulting\Workflows\DataTransferObjects\DryRunStep;
use RoundlyConsulting\Workflows\DataTransferObjects\SideEffect;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\DataTransferObjects\WouldBeAction;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Engine\Advancer;
use RoundlyConsulting\Workflows\Support\Engine\OutputBag;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\StepInputs;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\TriggerInputs;
use RoundlyConsulting\Workflows\Support\ValueScope;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use Throwable;

/**
 * Test mode: validates the graph, evaluates the trigger (input, condition, owner), then walks it
 * on a virtual clock. Pure steps evaluate for real against the subject (a clone) or a sample;
 * side-effect steps simulate, or are listed as "would run" with an assumed outcome. Writes
 * nothing: no handler runs, no model is saved, no job or event is dispatched, and the walk sits
 * inside a transaction that always rolls back. Only the optional log row is written, afterwards.
 */
final readonly class DryRunAction
{
    public function __construct(
        private ValidateGraphAction $validate,
        private StepCatalogue $catalogue,
        private SubjectRegistry $subjects,
        private Owners $owners,
        private RestoresOwnerContext $context,
    ) {}

    public function execute(DryRunData $data): DryRunResult
    {
        $owner = Definitions::owner($data->definition);
        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        $connection->beginTransaction();

        try {
            $result = $this->context->run($owner, fn (): DryRunResult => $this->walk($data, $owner));
        } finally {
            $connection->rollBack();
        }

        if ($data->log ?? WorkflowsConfig::dryRunLog()) {
            $this->log($data, $owner, $result);
        }

        return $result;
    }

    private function walk(DryRunData $data, ?Model $owner): DryRunResult
    {
        $report = $this->validate->execute($data->graph, $data->definition->subject_key, $owner, [], $data->definition->key);

        if (! $report->valid()) {
            return new DryRunResult(false, false, [], [], null, 'invalid', $report, 'invalid_graph');
        }

        $graph = GraphParser::parse($data->graph);
        $trigger = $graph->trigger();
        $subjectType = $data->definition->subject_key === null ? null : $this->subjects->find($data->definition->subject_key);
        $subject = $this->subject($data, $subjectType);
        $now = $data->at === null ? Clock::now() : Clock::utc($data->at);

        if ($trigger === null) {
            return new DryRunResult(true, false, [], [], null, 'not_started', $report, 'missing_trigger');
        }

        if ($owner !== null && $subject !== null && ! $this->owners->matches($owner, $subject)) {
            return new DryRunResult(true, false, [], [], null, 'not_started', $report, 'owner_mismatch');
        }

        try {
            $input = ($trigger->config['trigger'] ?? 'manual') === 'manual' ? TriggerInputs::validate($trigger->config, $data->input) : $data->input;
        } catch (InvalidTriggerInputException) {
            return new DryRunResult(true, false, [], [], null, 'not_started', $report, 'invalid_input');
        }

        $outputs = new OutputBag;
        $version = $this->version($data);
        $scope = new ValueScope(
            subject: $subject,
            subjectType: $subjectType,
            owner: $owner,
            ownerType: $owner === null ? null : $this->subjects->forModel($owner),
            input: $input,
            trigger: $data->event === null ? $data->payload : ['event' => $data->event, 'payload' => $data->payload],
            outputs: static fn (string $node): ?array => $outputs->get($node),
            now: $now,
            timezone: $this->timezone($graph),
            starter: $data->requester,
            inputTypes: TriggerInputs::declared($trigger->config),
        );

        if (is_array($trigger->config['condition'] ?? null) && ! Evaluator::evaluate($trigger->config['condition'], $scope)) {
            return new DryRunResult(true, false, [], [], null, 'not_started', $report, 'condition_false');
        }

        $instance = $this->instance($data, $version, $owner, $subject);
        $path = [new DryRunStep($trigger->id, 'trigger', 'next', false, $now->toIso8601String(), StepInputs::capped(['input' => $input, 'trigger' => $scope->trigger]))];
        $actions = [];
        $visits = [$trigger->id => 1];
        $outputs->set($trigger->id, ['input' => $input, 'trigger' => $scope->trigger]);
        $limit = Advancer::maxSteps($graph);
        $edge = $graph->edgeFor($trigger->id, 'next');

        while ($edge !== null) {
            if (count($path) + 1 > $limit) {
                return new DryRunResult(true, true, $path, $actions, null, 'limit', $report);
            }

            $node = $graph->node($edge->to);
            $type = $node === null ? null : $this->catalogue->find($node->type);

            if ($node === null || $type === null) {
                return new DryRunResult(true, true, $path, $actions, null, 'invalid', $report);
            }

            $visits[$node->id] = ($visits[$node->id] ?? 0) + 1;
            $context = $this->context($instance, $version, $graph, $node, $visits[$node->id], $scope->at($now));

            $stepInput = $context->resolvedConfig();
            [$outcome, $assumed, $output, $step] = $this->step($type, $node, $context, $data->assume);
            $actions = [...$actions, ...$step];
            $outputs->set($node->id, $output);

            foreach ($node->escalations as $level => $escalation) {
                $after = Durations::parse($escalation['after'] ?? null);
                $at = $after === null ? null : Durations::add($after, $now, $scope->timezone, is_string($graph->setting('calendar')) ? $graph->setting('calendar') : null, $owner);
                $actions[] = new WouldBeAction($node->id, 'escalation', 'would_run', 'escalation level '.($level + 1), ['due_at' => $at?->toIso8601String()]);
            }

            if (is_string($output['due_at'] ?? null)) {
                $now = CarbonImmutable::parse($output['due_at'])->utc();
            }

            $path[] = new DryRunStep($node->id, $node->type, $node->type === 'end' ? null : $outcome, $assumed, $now->toIso8601String(), $stepInput);

            if ($node->type === 'end') {
                return new DryRunResult(true, true, $path, $actions, $node->id, 'end', $report);
            }

            $edge = $graph->edgeFor($node->id, $outcome);
        }

        return new DryRunResult(true, true, $path, $actions, null, 'invalid', $report, 'missing_edge');
    }

    /**
     * @param  array<string, string>  $assume
     * @return array{0: string, 1: bool, 2: array<string, mixed>, 3: list<WouldBeAction>}
     */
    private function step(StepType $type, Node $node, StepContext $context, array $assume): array
    {
        $required = null;

        foreach ($type->outcomes($node->config) as $outcome) {
            if ($outcome->required) {
                $required = $outcome->key;

                break;
            }
        }

        try {
            $simulated = $type->simulate($context);
        } catch (Throwable) {
            $simulated = null;
        }

        if (isset($assume[$node->id])) {
            $simulated = null;
        }

        if ($simulated instanceof StepResult && $simulated->isComplete()) {
            $actions = array_map(
                static fn (SideEffect $would): WouldBeAction => new WouldBeAction($node->id, $node->type, 'simulated', $would->description, $would->details),
                $simulated->wouldDo,
            );

            $assumed = $node->type === 'wait' && ! is_array($node->config['condition'] ?? null);

            return [$simulated->outcome, $assumed, $simulated->output, $actions];
        }

        $actions = $type->hasSideEffects() ? [new WouldBeAction($node->id, $node->type, 'would_run', $type->label())] : [];

        return [$assume[$node->id] ?? $required ?? 'next', true, [], $actions];
    }

    private function context(WorkflowInstance $instance, WorkflowVersion $version, Graph $graph, Node $node, int $visit, ValueScope $scope): StepContext
    {
        $runModel = WorkflowModels::stepRun();
        $run = new $runModel;
        $run->node_id = $node->id;
        $run->node_type = $node->type;
        $run->visit = $visit;
        $run->attempt = 1;
        $run->status = StepRunStatus::Running;
        $run->idempotency_key = 'dry:'.$node->id.':'.$visit;

        return new StepContext(
            instance: $instance,
            stepRun: $run,
            node: $node,
            graph: $graph,
            version: $version,
            actor: $version->actor_mode === ActorMode::OnBehalfOf && $scope->starter !== null ? Actor::onBehalfOf($scope->starter) : Actor::system(),
            scope: $scope,
            signal: null,
            simulating: true,
            definitionKey: $instance->definition?->key,
        );
    }

    private function subject(DryRunData $data, ?SubjectTypeDefinition $type): ?Model
    {
        if ($data->subject !== null) {
            // A clone with no relations: lazy loads during the walk never reach the caller's model.
            $clone = clone $data->subject;
            $clone->setRelations([]);

            return $clone;
        }

        if ($data->sample === null || $type === null) {
            return null;
        }

        $class = $type->model;
        $sample = new $class;
        $attributes = [];

        foreach ($data->sample as $name => $value) {
            $field = $type->field((string) $name);

            if ($field !== null && ! $field->isRelationPath()) {
                $attributes[$field->path()] = $value;
            }
        }

        return $sample->forceFill($attributes);
    }

    private function version(DryRunData $data): WorkflowVersion
    {
        $model = WorkflowModels::version();
        $version = $data->versionId === null ? null : $model::query()->find($data->versionId);

        if ($version instanceof WorkflowVersion) {
            return $version;
        }

        $version = new $model;
        $version->definition_id = Keys::of($data->definition);
        $version->number = 0;
        $version->graph = $data->graph;
        $version->trigger = GraphParser::parse($data->graph)->trigger()->config ?? [];
        $version->settings = GraphParser::parse($data->graph)->settings;
        $version->actor_mode = ($version->settings['actor'] ?? 'system') === 'on_behalf_of' ? ActorMode::OnBehalfOf : ActorMode::System;

        return $version;
    }

    private function instance(DryRunData $data, WorkflowVersion $version, ?Model $owner, ?Model $subject): WorkflowInstance
    {
        $model = WorkflowModels::instance();
        $instance = new $model;
        $instance->ulid = (string) Str::ulid();
        $instance->status = InstanceStatus::Running;
        $instance->trigger_type = TriggerType::tryFrom(is_string($version->trigger['trigger'] ?? null) ? $version->trigger['trigger'] : 'manual') ?? TriggerType::Manual;
        $instance->actor_mode = $version->actor_mode;
        $instance->depth = 0;
        $instance->steps_count = 0;
        $instance->owner_type = $owner?->getMorphClass();
        $instance->owner_id = Keys::nullable($owner);
        $instance->subject_type = $subject?->getMorphClass();
        $instance->subject_id = self::key($subject);
        $instance->setRelation('definition', $data->definition);
        $instance->setRelation('version', $version);

        return $instance;
    }

    private function timezone(Graph $graph): string
    {
        $timezone = $graph->setting('timezone');

        return is_string($timezone) && WorkflowsConfig::isTimezone($timezone) ? $timezone : WorkflowsConfig::timezone();
    }

    private function log(DryRunData $data, ?Model $owner, DryRunResult $result): void
    {
        $model = WorkflowModels::dryRun();
        $row = new $model;
        $row->definition_id = Keys::of($data->definition);
        $row->version_id = $data->versionId;
        $row->owner_type = $owner?->getMorphClass();
        $row->owner_id = Keys::nullable($owner);
        $row->subject_type = $data->subject?->getMorphClass();
        $row->subject_id = self::key($data->subject);
        $row->input = $data->input;
        $row->result = $result->toArray();
        $row->requester_type = $data->requester?->getMorphClass();
        $row->requester_id = Keys::nullable($data->requester);
        $row->save();
    }

    private static function key(?Model $model): int|string|null
    {
        $key = $model?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
