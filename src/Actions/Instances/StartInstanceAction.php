<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Events\InstanceFailed;
use RoundlyConsulting\Workflows\Events\InstanceStarted;
use RoundlyConsulting\Workflows\Exceptions\DefinitionNotActiveException;
use RoundlyConsulting\Workflows\Exceptions\MissingTriggeringUserException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\SubjectTypeMismatchException;
use RoundlyConsulting\Workflows\Exceptions\TriggerMismatchException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Engine\Advancer;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\KeyTypes;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\StepInputs;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\TriggerForms;
use RoundlyConsulting\Workflows\Support\TriggerInputs;
use RoundlyConsulting\Workflows\Support\ValueScope;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Starts one instance of a definition's current version, inside the instance owner's context:
 * the trigger type must be this start path's, the subject must be of the definition's subject
 * type and belong to its owner, the input is validated, the trigger condition is evaluated, then —
 * under the definition row lock — the idempotency key (one instance per key), the start-rate
 * limit, the instance, its completed trigger step and the first queued step.
 */
final readonly class StartInstanceAction
{
    /**
     * Re-reads after a version published between the first read and the definition lock.
     */
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private RestoresOwnerContext $context,
        private SubjectRegistry $subjects,
        private Owners $owners,
        private Advancer $advancer,
        private TriggerForms $forms,
    ) {}

    public function execute(StartData $data): StartResult
    {
        $definition = Definitions::find($data->key, $data->owner);
        $definitionOwner = Definitions::owner($definition);
        $instanceOwner = $definitionOwner ?? ($data->subject === null ? null : $this->owners->ownerOf($data->subject));

        return $this->context->run($instanceOwner, fn (): StartResult => $this->start($definition, $data, $definitionOwner, $instanceOwner));
    }

    private function start(WorkflowDefinition $definition, StartData $data, ?Model $definitionOwner, ?Model $instanceOwner, int $attempt = 1): StartResult
    {
        $version = $definition->currentVersion;

        if (! $definition->isActive() || ! $version instanceof WorkflowVersion) {
            throw DefinitionNotActiveException::key($definition->key);
        }

        $trigger = $data->parent !== null ? TriggerType::Manual : $data->trigger;

        if ($version->trigger_type !== $trigger) {
            throw TriggerMismatchException::expected($definition->key, $version->trigger_type->value, $trigger->value);
        }

        $this->checkSubject($definition, $data->subject, $definitionOwner);

        KeyTypes::assertValue('owner', $instanceOwner);
        KeyTypes::assertValue('user', $data->by);

        if ($version->actor_mode === ActorMode::OnBehalfOf && $data->by === null) {
            throw MissingTriggeringUserException::key($definition->key);
        }

        $input = $this->input($version, $data);

        if (! $this->conditionHolds($version, $data, $instanceOwner, $input)) {
            return new StartResult(null, false, skipped: 'condition_false', definition: $definition->key);
        }

        $after = [];
        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        $result = $connection->transaction(function () use ($definition, $version, $data, $instanceOwner, $input, $trigger, $attempt, &$after): ?StartResult {
            $definitionModel = WorkflowModels::definition();
            $locked = $definitionModel::query()->lockForUpdate()->find(Keys::of($definition));

            // What was read before the lock may be stale: a definition retired meanwhile starts
            // nothing, and a version published meanwhile is the one this start runs on.
            if (! $locked instanceof WorkflowDefinition || ! $locked->isActive()) {
                throw DefinitionNotActiveException::key($definition->key);
            }

            if ((string) $locked->current_version_id !== (string) Keys::of($version) && $attempt < self::MAX_ATTEMPTS) {
                return null;
            }

            $digest = $data->idempotencyKey === null ? null : Crypto::digest()->hex(Keys::of($definition).'|'.$data->idempotencyKey);

            if ($digest !== null) {
                $winner = $this->claimKey($definition, $digest, $data->internalKey);

                if ($winner !== null) {
                    return new StartResult($winner, false, definition: $definition->key);
                }
            }

            $instance = $this->create($definition, $version, $data, $instanceOwner, $input, $trigger, $digest);

            if ($digest !== null) {
                $ledger = WorkflowModels::idempotencyKey();
                $ledger::query()->where('scope', 'start')->where('key', $digest)->update(['instance_id' => Keys::of($instance)]);
            }

            if ($this->rateExceeded($definition)) {
                $this->refuse($instance, $after);

                return new StartResult($instance, true, definition: $definition->key);
            }

            $this->run($instance, $version, $after);

            return new StartResult($instance, true, definition: $definition->key);
        });

        if ($result === null) {
            return $this->start($definition->refresh(), $data, $definitionOwner, $instanceOwner, $attempt + 1);
        }

        foreach ($after as $callback) {
            $callback();
        }

        $result->instance?->refresh();

        return $result;
    }

    private function checkSubject(WorkflowDefinition $definition, ?Model $subject, ?Model $owner): void
    {
        if ($definition->subject_key === null) {
            if ($subject !== null) {
                throw SubjectTypeMismatchException::expected('none', $this->subjects->forModel($subject)->key ?? class_basename($subject));
            }

            return;
        }

        $type = $subject === null ? null : $this->subjects->forModel($subject);

        if ($subject === null || $type === null || $type->key !== $definition->subject_key) {
            throw SubjectTypeMismatchException::expected($definition->subject_key, $subject === null ? 'none' : ($type->key ?? class_basename($subject)));
        }

        KeyTypes::assertValue('subject', $subject);

        if ($owner !== null && ! $this->owners->matches($owner, $subject)) {
            throw SubjectOwnerMismatchException::forSubject();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function input(WorkflowVersion $version, StartData $data): array
    {
        if ($version->trigger_type !== TriggerType::Manual) {
            return $data->input;
        }

        $form = $version->trigger['form'] ?? null;

        if (is_string($form) && $form !== '') {
            return $this->forms->validate($form, $data->input, $data->request, $data->submitForm, $data->by);
        }

        return TriggerInputs::validate($version->trigger, $data->input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function conditionHolds(WorkflowVersion $version, StartData $data, ?Model $owner, array $input): bool
    {
        $condition = $version->trigger['condition'] ?? null;

        if (! is_array($condition)) {
            return true;
        }

        $scope = new ValueScope(
            subject: $data->subject,
            subjectType: $data->subject === null ? null : $this->subjects->forModel($data->subject),
            owner: $owner,
            ownerType: $owner === null ? null : $this->subjects->forModel($owner),
            input: $input,
            trigger: $data->payload,
            outputs: static fn (string $node): ?array => null,
            now: Clock::now(),
            timezone: Runtime::timezone($version),
            starter: $data->by,
            inputTypes: TriggerInputs::declared($version->trigger),
        );

        return Evaluator::evaluate($condition, $scope);
    }

    /**
     * The start half of the idempotency ledger. The insert runs in its own savepoint: on
     * PostgreSQL a unique violation would otherwise abort the enclosing (maybe the host's)
     * transaction, and the winner is then read with a locking read.
     */
    private function claimKey(WorkflowDefinition $definition, string $digest, bool $internal): ?WorkflowInstance
    {
        $ledger = WorkflowModels::idempotencyKey();
        $connection = (new $ledger)->getConnection();
        $now = Clock::now();

        $existing = $ledger::query()->where('scope', 'start')->where('key', $digest)->lockForUpdate()->first();

        if ($existing instanceof WorkflowIdempotencyKey) {
            if ($existing->expires_at !== null && $existing->expires_at->lessThanOrEqualTo($now)) {
                $existing->delete();
            } else {
                return $this->winner($existing);
            }
        }

        try {
            $connection->transaction(static function () use ($ledger, $digest, $definition, $internal, $now): void {
                $row = new $ledger;
                $row->scope = 'start';
                $row->key = $digest;
                $row->definition_id = Keys::of($definition);
                $row->expires_at = $internal ? null : $now->add(WorkflowsConfig::idempotencyTtl());
                $row->save();
            });
        } catch (UniqueConstraintViolationException) {
            $winner = $ledger::query()->where('scope', 'start')->where('key', $digest)->lockForUpdate()->first();

            return $winner instanceof WorkflowIdempotencyKey ? $this->winner($winner) : null;
        }

        return null;
    }

    private function winner(WorkflowIdempotencyKey $row): ?WorkflowInstance
    {
        $model = WorkflowModels::instance();
        $instance = $row->instance_id === null ? null : $model::query()->find($row->instance_id);

        return $instance instanceof WorkflowInstance ? $instance : null;
    }

    /**
     * Refused (rate-failed) starts are not counted: a steady flood would otherwise keep the
     * window full of its own rejections and admit nothing.
     */
    private function rateExceeded(WorkflowDefinition $definition): bool
    {
        $max = WorkflowsConfig::maxStartsPerMinute();

        if ($max === null) {
            return false;
        }

        $model = WorkflowModels::instance();

        $count = $model::query()
            ->where('definition_id', Keys::of($definition))
            ->where('created_at', '>', Clock::format(Clock::now()->subMinute()))
            ->where(static fn ($query) => $query->whereNull('failure_code')->orWhere('failure_code', '<>', 'start_rate_exceeded'))
            ->count();

        return $count > $max;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function create(WorkflowDefinition $definition, WorkflowVersion $version, StartData $data, ?Model $owner, array $input, TriggerType $trigger, ?string $digest): WorkflowInstance
    {
        $model = WorkflowModels::instance();
        $parent = $data->parent?->instance;

        $instance = new $model;
        $instance->ulid = (string) Str::ulid();
        $instance->definition_id = Keys::of($definition);
        $instance->version_id = Keys::of($version);
        $instance->owner_type = $owner?->getMorphClass();
        $instance->owner_id = Keys::nullable($owner);
        $instance->subject_key = $definition->subject_key;
        $instance->subject_type = $data->subject?->getMorphClass();
        $instance->subject_id = Keys::nullable($data->subject);
        $instance->trigger_type = $trigger;
        $instance->trigger_key = $version->trigger_key;
        $instance->trigger_payload = ['input' => $input, 'trigger' => $data->payload];
        $instance->idempotency_digest = $digest;
        $instance->status = InstanceStatus::Running;
        $instance->steps_count = 0;
        $instance->depth = $parent instanceof WorkflowInstance ? $parent->depth + 1 : 0;
        $instance->parent_instance_id = Keys::nullable($parent);
        $instance->parent_step_run_id = Keys::nullable($data->parent);
        $instance->root_instance_id = $parent instanceof WorkflowInstance ? ($parent->root_instance_id ?? Keys::of($parent)) : null;
        $instance->actor_mode = $version->actor_mode;
        $instance->starter_type = $data->by?->getMorphClass();
        $instance->starter_id = Keys::nullable($data->by);
        $instance->started_at = Clock::now();
        $instance->lock_version = 0;
        $instance->save();

        return $instance;
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function refuse(WorkflowInstance $instance, array &$after): void
    {
        $limit = (int) WorkflowsConfig::maxStartsPerMinute();
        $message = Messages::get('failures.start_rate_exceeded', ['limit' => $limit]);

        $instance->status = InstanceStatus::Failed;
        $instance->failure_code = 'start_rate_exceeded';
        $instance->failure_message = $message;
        $instance->ended_at = Clock::now();
        $instance->save();

        Events::dispatch(new InstanceFailed($instance, 'start_rate_exceeded', $message, null, $instance, Runtime::actor($instance)));
    }

    /**
     * @param  list<Closure(): mixed>  $after
     */
    private function run(WorkflowInstance $instance, WorkflowVersion $version, array &$after): void
    {
        $graph = $version->parsedGraph();
        $trigger = $graph->trigger();

        Events::dispatch(new InstanceStarted($instance, $instance, Runtime::actor($instance)));

        if ($trigger === null) {
            $this->advancer->fail($instance, FailureCode::MissingTrigger, Messages::get('failures.missing_trigger'), null, $after);

            return;
        }

        $run = $this->advancer->createRun($instance, $trigger);
        $now = Clock::now();
        $run->status = StepRunStatus::Completed;
        $run->outcome = 'next';
        $run->output = ['input' => $instance->trigger_payload['input'] ?? [], 'trigger' => $instance->trigger_payload['trigger'] ?? []];
        $run->input = StepInputs::capped($run->output);
        $run->started_at = $now;
        $run->finished_at = $now;
        $run->save();

        $instance->steps_count = 1;
        $instance->current_node_id = $trigger->id;
        $instance->save();

        $edge = $graph->edgeFor($trigger->id, 'next');

        if ($edge === null) {
            $this->advancer->fail($instance, FailureCode::MissingOutcomeEdge, Messages::get('failures.missing_outcome_edge', ['node' => $trigger->id, 'outcome' => 'next']), $trigger->id, $after);

            return;
        }

        $this->advancer->advance($instance, $graph, $edge->to, $after);
    }
}
