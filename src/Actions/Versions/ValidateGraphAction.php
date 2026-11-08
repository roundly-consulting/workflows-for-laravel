<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Versions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Graph\CycleFinder;
use RoundlyConsulting\Workflows\Graph\Edge;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Graph\Reachability;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\EscalationActions;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\PartyValidator;
use RoundlyConsulting\Workflows\Support\Schema\SchemaValidator;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\TriggerInputs;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Publish validation, machine-readable per node and edge: structure (stops early), catalogue,
 * config (schema, semantics, escalations), trigger/end, outcomes, reachability, unguarded
 * cycles, fields and refs, actor, unresolved import flags, cross-definition starts. Every other
 * pass runs, and every finding is collected.
 */
final readonly class ValidateGraphAction
{
    public function __construct(
        private StepCatalogue $catalogue,
        private SubjectRegistry $subjects,
        private CalendarRegistry $calendars,
        private EscalationActions $escalationActions,
        private Owners $owners,
    ) {}

    /**
     * @param  array<array-key, mixed>  $graph
     * @param  list<ReviewFlag>  $reviewFlags
     */
    public function execute(array $graph, ?string $subjectType = null, ?Model $owner = null, array $reviewFlags = [], ?string $definitionKey = null): ValidationReport
    {
        $structural = GraphParser::structure($graph);

        if ($structural !== []) {
            return new ValidationReport($structural);
        }

        $parsed = GraphParser::parse($graph);
        $errors = [];

        $subjectDefinition = null;

        if ($subjectType !== null) {
            $subjectDefinition = $this->subjects->find($subjectType);

            if ($subjectDefinition === null) {
                $errors[] = ValidationError::make(ValidationCode::UnknownSubjectType, params: ['subject_type' => $subjectType]);
            }
        }

        $ownerDefinition = $owner === null ? null : $this->subjects->forModel($owner);
        $trigger = $parsed->trigger();

        $scope = new ValidationScope(
            graph: $parsed,
            node: null,
            subjectType: $subjectDefinition,
            ownerType: $ownerDefinition,
            owner: $owner,
            inputs: $trigger === null ? [] : TriggerInputs::declared($trigger->config),
            definitionKey: $definitionKey,
        );

        $errors = [...$errors, ...$this->settings($parsed, $owner)];

        $types = [];

        foreach ($parsed->nodes as $node) {
            $type = $this->catalogue->find($node->type);
            $package = $this->catalogue->missingAdapter($node->type);

            if ($package !== null) {
                $errors[] = ValidationError::make(ValidationCode::AdapterMissing, node: $node->id, params: ['type' => $node->type, 'package' => $package]);

                continue;
            }

            if ($type === null) {
                $errors[] = ValidationError::make(ValidationCode::UnknownNodeType, node: $node->id, params: ['type' => $node->type]);

                continue;
            }

            $types[$node->id] = $type;
            $errors = [...$errors, ...$this->config($node, $type, $scope->forNode($node), $owner)];
        }

        $errors = [...$errors, ...$this->triggerAndEnd($parsed)];
        $errors = [...$errors, ...$this->outcomes($parsed, $types)];

        if ($trigger !== null) {
            foreach (Reachability::unreachable($parsed, $trigger->id) as $id) {
                $errors[] = ValidationError::make(ValidationCode::UnreachableNode, node: $id);
            }
        }

        foreach (CycleFinder::unguarded($parsed, self::guards(...)) as $cycle) {
            $errors[] = ValidationError::make(ValidationCode::CycleWithoutBreaker, node: $cycle[0], params: ['nodes' => $cycle]);
        }

        if ($trigger !== null && $owner !== null && $subjectDefinition !== null && ! $this->owners->resolvable($subjectDefinition->model)) {
            $errors[] = ValidationError::make(ValidationCode::SubjectOwnerUnresolvable, node: $trigger->id, params: ['subject_type' => $subjectDefinition->key]);
        }

        $kind = $trigger?->config['trigger'] ?? null;

        if (($parsed->settings['actor'] ?? 'system') === 'on_behalf_of' && in_array($kind, ['schedule', 'webhook'], true)) {
            $errors[] = ValidationError::make(ValidationCode::ActorRequiresUser, node: $trigger->id, path: '/trigger', params: ['trigger' => $kind]);
        }

        foreach ($reviewFlags as $flag) {
            $errors[] = ValidationError::make(ValidationCode::UnresolvedImportReference, node: $flag->node, path: $flag->path, params: ['reason' => $flag->reason]);
        }

        return new ValidationReport($errors);
    }

    /**
     * @return list<ValidationError>
     */
    private function settings(Graph $graph, ?Model $owner): array
    {
        $errors = [];
        $settings = $graph->settings;

        if (isset($settings['actor']) && ! in_array($settings['actor'], ['system', 'on_behalf_of'], true)) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, path: '/settings/actor', params: ['keyword' => 'enum', 'expected' => 'system | on_behalf_of']);
        }

        if (isset($settings['timezone']) && (! is_string($settings['timezone']) || ! WorkflowsConfig::isTimezone($settings['timezone']))) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, path: '/settings/timezone', params: ['keyword' => 'format', 'expected' => 'timezone']);
        }

        if (isset($settings['calendar']) && (! is_string($settings['calendar']) || ! $this->calendars->has($settings['calendar'], $owner))) {
            $errors[] = ValidationError::make(ValidationCode::UnknownCalendar, path: '/settings/calendar', params: ['calendar' => is_string($settings['calendar']) ? $settings['calendar'] : '']);
        }

        $limits = $settings['limits'] ?? null;

        if ($limits !== null) {
            $max = is_array($limits) ? ($limits['max_steps'] ?? null) : null;

            if (! is_array($limits) || ($max !== null && (! is_int($max) || $max < 2 || $max > WorkflowsConfig::maxSteps()))) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, path: '/settings/limits/max_steps', params: ['keyword' => 'maximum', 'expected' => (string) WorkflowsConfig::maxSteps()]);
            }
        }

        if (isset($settings['retry']) && ! $this->retryBlock($settings['retry'])) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, path: '/settings/retry', params: ['keyword' => 'retry', 'expected' => '{max_attempts, backoff}']);
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function config(Node $node, StepType $type, ValidationScope $scope, ?Model $owner): array
    {
        $errors = [];

        foreach (SchemaValidator::validate($type->configSchema(), $node->config) as $error) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: $error->path === '' ? '/' : $error->path, params: ['keyword' => $error->keyword, 'expected' => $error->expected]);
        }

        if ($errors !== []) {
            return $errors;
        }

        $errors = [...$errors, ...$type->validateConfig($node->config, $scope)];
        $errors = [...$errors, ...$this->calendarsIn($node->config, $node->id, '', $owner)];

        if ($node->retry !== null && ! $this->retryBlock($node->retry)) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: '/retry', params: ['keyword' => 'retry', 'expected' => '{max_attempts, backoff}']);
        }

        if ($node->escalations !== []) {
            $errors = [...$errors, ...$this->escalations($node, $type, $scope, $owner)];
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function escalations(Node $node, StepType $type, ValidationScope $scope, ?Model $owner): array
    {
        if (! $type->supportsEscalations()) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: '/escalations', params: ['keyword' => 'escalations', 'expected' => 'a type that supports escalations'])];
        }

        if (count($node->escalations) > WorkflowsConfig::escalationsMaxLevels()) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: '/escalations', params: ['keyword' => 'maxItems', 'expected' => (string) WorkflowsConfig::escalationsMaxLevels()])];
        }

        $errors = [];

        foreach ($node->escalations as $level => $escalation) {
            $path = '/escalations/'.$level;

            if (Durations::parse($escalation['after'] ?? null) === null) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: $path.'/after', params: ['keyword' => 'duration', 'expected' => 'duration']);
            } else {
                $errors = [...$errors, ...$this->calendarsIn(['after' => $escalation['after']], $node->id, $path, $owner)];
            }

            $actions = $escalation['actions'] ?? null;

            if (! is_array($actions) || ! array_is_list($actions) || $actions === [] || count($actions) > 5) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: $path.'/actions', params: ['keyword' => 'actions', 'expected' => '1 to 5 actions']);

                continue;
            }

            foreach ($actions as $index => $action) {
                $errors = [...$errors, ...$this->action($node, $type, $action, $scope, $path.'/actions/'.$index)];
            }
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function action(Node $node, StepType $type, mixed $action, ValidationScope $scope, string $path): array
    {
        $kind = is_array($action) ? ($action['type'] ?? null) : null;

        if (! is_array($action)) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: $path, params: ['keyword' => 'type', 'expected' => 'notify | reassign | start_workflow'])];
        }

        /** @var array<string, mixed> $action */
        return match (true) {
            $kind === 'reassign' => $type->supportsReassign()
                ? PartyValidator::validate($action['to'] ?? null, $scope, $path.'/to')
                : [ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: $path.'/type', params: ['keyword' => 'reassign', 'expected' => 'a type that supports reassign'])],
            $kind === 'start_workflow' => $this->startTarget($action, $scope, $path),
            is_string($kind) && $this->escalationActions->has($kind) => $this->escalationActions->validate($kind, $action, $scope, $path),
            $kind === 'notify' => [ValidationError::make(ValidationCode::AdapterMissing, node: $node->id, path: $path, params: ['type' => 'notify', 'package' => StepCatalogue::ADAPTERS['notify']])],
            default => [ValidationError::make(ValidationCode::InvalidConfig, node: $node->id, path: $path.'/type', params: ['keyword' => 'enum', 'expected' => 'notify | reassign | start_workflow'])],
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<ValidationError>
     */
    private function startTarget(array $action, ValidationScope $scope, string $path): array
    {
        $type = $this->catalogue->find('start_workflow');

        if ($type === null || ! is_string($action['definition'] ?? null)) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $scope->node?->id, path: $path.'/definition', params: ['keyword' => 'required', 'expected' => 'definition'])];
        }

        $errors = [];

        foreach ($type->validateConfig(['definition' => $action['definition'], 'wait' => false], $scope) as $error) {
            $errors[] = new ValidationError($error->code, $error->node, $error->edge, $path.($error->path ?? ''), $error->params, $error->severity, $error->message);
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function triggerAndEnd(Graph $graph): array
    {
        $errors = [];
        $triggers = $graph->nodesOfType('trigger');

        if (count($triggers) !== 1) {
            $errors[] = ValidationError::make(ValidationCode::TriggerCount, node: $triggers[1]->id ?? null, params: ['count' => count($triggers)]);
        }

        if ($graph->nodesOfType('end') === []) {
            $errors[] = ValidationError::make(ValidationCode::NoEnd);
        }

        foreach ($triggers as $trigger) {
            foreach ($graph->incoming($trigger->id) as $edge) {
                $errors[] = ValidationError::make(ValidationCode::TriggerHasIncoming, node: $trigger->id, edge: $edge->reference());
            }
        }

        foreach ($graph->nodesOfType('end') as $end) {
            foreach ($graph->outgoing($end->id) as $edge) {
                $errors[] = ValidationError::make(ValidationCode::EndHasOutgoing, node: $end->id, edge: $edge->reference());
            }
        }

        return $errors;
    }

    /**
     * What makes a cycle legal (OQ-11): a loop counter's `continue` edge (its `exhausted` edge is
     * the way out, never a guard), or the way out of a delay that waits a positive `for`. A delay
     * until an instant or a field waits once at most — on the next round the date has passed.
     */
    private static function guards(Edge $edge, Node $from): bool
    {
        return match ($from->type) {
            'loop' => $edge->outcome === 'continue',
            'delay' => Durations::parse($from->config['for'] ?? null)?->isPositive() === true,
            default => false,
        };
    }

    /**
     * @param  array<string, StepType>  $types
     * @return list<ValidationError>
     */
    private function outcomes(Graph $graph, array $types): array
    {
        $errors = [];

        foreach ($graph->nodes as $node) {
            $type = $types[$node->id] ?? null;

            if ($type === null || $node->type === 'end') {
                continue;
            }

            $declared = [];

            foreach ($type->outcomes($node->config) as $outcome) {
                $declared[$outcome->key] = $outcome;
            }

            $seen = [];

            foreach ($graph->outgoing($node->id) as $edge) {
                // An unlabelled edge stands for `next` only — the engine follows it for nothing else,
                // so a single `started` or `received` outcome still needs its label.
                if ($edge->outcome === null && array_keys($declared) !== ['next']) {
                    $errors[] = ValidationError::make(ValidationCode::OutcomeLabelRequired, node: $node->id, edge: $edge->reference(), params: ['outcome' => implode(', ', array_keys($declared))]);

                    continue;
                }

                $outcome = $edge->outcome ?? 'next';

                if (! isset($declared[$outcome])) {
                    $errors[] = ValidationError::make(ValidationCode::UnknownOutcome, node: $node->id, edge: $edge->reference(), params: ['outcome' => $edge->outcome ?? '']);

                    continue;
                }

                if (isset($seen[$outcome])) {
                    $errors[] = ValidationError::make(ValidationCode::DuplicateOutcome, node: $node->id, edge: $edge->reference(), params: ['outcome' => $outcome]);

                    continue;
                }

                $seen[$outcome] = true;
            }

            foreach ($declared as $key => $outcome) {
                if ($outcome->required && ! isset($seen[$key])) {
                    $errors[] = ValidationError::make(ValidationCode::MissingOutcome, node: $node->id, params: ['outcome' => $key]);
                }
            }
        }

        return $errors;
    }

    /**
     * Every `{amount, unit, calendar}` duration in a config names a calendar that exists.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<ValidationError>
     */
    private function calendarsIn(array $config, string $node, string $path, ?Model $owner): array
    {
        $errors = [];

        foreach ($config as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (isset($value['unit'], $value['amount']) && is_string($value['calendar'] ?? null) && ! $this->calendars->has($value['calendar'], $owner)) {
                $errors[] = ValidationError::make(ValidationCode::UnknownCalendar, node: $node, path: $path.'/'.$key.'/calendar', params: ['calendar' => $value['calendar']]);

                continue;
            }

            $errors = [...$errors, ...$this->calendarsIn($value, $node, $path.'/'.$key, $owner)];
        }

        return $errors;
    }

    private function retryBlock(mixed $block): bool
    {
        if (! is_array($block)) {
            return false;
        }

        $attempts = $block['max_attempts'] ?? 1;
        $backoff = $block['backoff'] ?? [];

        return is_int($attempts) && $attempts >= 1 && $attempts <= WorkflowsConfig::maxAttemptsCap()
            && is_array($backoff) && array_is_list($backoff)
            && array_filter($backoff, static fn (mixed $seconds): bool => ! is_int($seconds) || $seconds < 0) === [];
    }
}
