<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Engine;

use RoundlyConsulting\Workflows\Actions\Instances\StartInstanceAction;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Events\EscalationFailed;
use RoundlyConsulting\Workflows\Events\EscalationTriggered;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Authorization;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Engine\ExceptionMap;
use RoundlyConsulting\Workflows\Support\Engine\RetryPolicies;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Engine\Waits;
use RoundlyConsulting\Workflows\Support\EscalationActions;
use RoundlyConsulting\Workflows\Support\Events;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use Throwable;

/**
 * Fires one escalation level inside the owner context, under the instance lock: a step that has
 * finished meanwhile cancels it instead. The actions run in order, each authorized and with its
 * own idempotency key (`esc:{visit key}:{level}:{action}`). Then the next level is scheduled from
 * now. A failing action is retried; once exhausted the escalation fails — the step never does.
 *
 * @internal
 */
final readonly class FireEscalationAction
{
    public function __construct(
        private RestoresOwnerContext $context,
        private StepCatalogue $catalogue,
        private EscalationActions $actions,
        private Authorization $authorization,
        private ExceptionMap $exceptions,
    ) {}

    public function execute(int|string $escalationId): void
    {
        $model = WorkflowModels::escalation();
        $escalation = $model::query()->find($escalationId);
        $instance = $escalation instanceof WorkflowEscalation ? $escalation->instance : null;

        if (! $escalation instanceof WorkflowEscalation || ! $instance instanceof WorkflowInstance) {
            return;
        }

        $this->context->run(Runtime::owner($instance), fn () => $this->fire(Keys::of($instance), Keys::of($escalation)));
    }

    private function fire(int|string $instanceId, int|string $escalationId): void
    {
        $instanceModel = WorkflowModels::instance();
        $connection = (new $instanceModel)->getConnection();

        $connection->transaction(function () use ($instanceModel, $instanceId, $escalationId, $connection): void {
            $instance = $instanceModel::query()->lockForUpdate()->find($instanceId);
            $escalationModel = WorkflowModels::escalation();
            $escalation = $escalationModel::query()->lockForUpdate()->find($escalationId);

            if (! $instance instanceof WorkflowInstance || ! $escalation instanceof WorkflowEscalation
                || ! in_array($escalation->status, [EscalationStatus::Pending, EscalationStatus::Claimed], true)) {
                return;
            }

            $run = $this->liveRun($escalation);
            $version = $instance->version;
            $graph = $version?->parsedGraph();
            $node = $run === null ? null : $graph?->node($run->node_id);
            $type = $node === null ? null : $this->catalogue->find($node->type);

            if (! $instance->status->isActive() || $run === null || ! $version instanceof WorkflowVersion || $graph === null || $node === null || $type === null) {
                $escalation->status = EscalationStatus::Cancelled;
                $escalation->cancelled_at = Clock::now();
                $escalation->save();

                return;
            }

            $subject = $instance->subject_type === null ? null : Runtime::subject($instance);
            $owner = Runtime::owner($instance);
            $context = Runtime::context($instance, $run, $version, $graph, $node, $subject, $owner);

            try {
                // A savepoint: a failing action leaves no half-written effect behind.
                $connection->transaction(function () use ($escalation, $context, $type, $node): void {
                    foreach ($escalation->actions as $index => $action) {
                        $this->action($context, $type, $node, $action, $escalation->level, $index);
                    }
                });
            } catch (Throwable $exception) {
                $this->failed($instance, $run, $escalation, $node, $graph, $type, $exception);

                return;
            }

            $now = Clock::now();
            $escalation->status = EscalationStatus::Fired;
            $escalation->fired_at = $now;
            $escalation->attempts++;
            $escalation->save();

            Waits::level($instance, $this->firstRun($escalation), $node, $escalation->level + 1, $now, Runtime::timezone($version), Waits::forGraph($graph), $owner);

            Events::dispatch(new EscalationTriggered($instance, $run, $escalation->level, $escalation->actions, $instance, Runtime::actor($instance)));
        });
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function action(StepContext $context, StepType $type, Node $node, array $action, int $level, int $index): void
    {
        $kind = is_string($action['type'] ?? null) ? $action['type'] : '';
        $key = 'esc:'.$context->idempotencyKey().':'.$level.':'.$index;

        $this->authorization->check($context->actor(), match ($kind) {
            'reassign' => $type->ability(),
            default => null,
        }, $context->subject(), $node, $context->instance());

        match (true) {
            // Once per escalation action: a retried escalation never replaces the round twice.
            $kind === 'reassign' => $context->once('reassign:'.$level.':'.$index, static function () use ($type, $context, $action): bool {
                $type->reassign($context, PartyReference::listFrom($action['to'] ?? []));

                return true;
            }),
            $kind === 'start_workflow' => $this->startWorkflow($context, $action, $key),
            $this->actions->has($kind) => $this->actions->run($kind, $context, $action, $key),
            default => throw StepFailedException::because(FailureCode::UnknownEscalationAction, Messages::get('failures.unknown_escalation_action', ['action' => $kind])),
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function startWorkflow(StepContext $context, array $action, string $key): void
    {
        $definition = $context->instance()->definition;

        if ($context->instance()->depth + 1 > WorkflowsConfig::maxDepth()) {
            throw StepFailedException::because(FailureCode::NestingDepthExceeded, Messages::get('failures.nesting_depth_exceeded', ['limit' => WorkflowsConfig::maxDepth()]));
        }

        $owner = $definition === null ? null : Definitions::owner($definition);
        $definitionKey = is_string($action['definition'] ?? null) ? $action['definition'] : '';
        $child = Definitions::query($owner)->where('key', $definitionKey)->first();

        try {
            app(StartInstanceAction::class)->execute(new StartData(
                key: $definitionKey,
                subject: $child !== null && $child->subject_key === null ? null : $context->subject(),
                owner: $owner,
                by: $context->actor()->user,
                idempotencyKey: $key,
                parent: $context->stepRun(),
                internalKey: true,
            ));
        } catch (WorkflowsException $exception) {
            throw StepFailedException::because($exception->errorCode(), $exception->getMessage());
        }
    }

    private function failed(WorkflowInstance $instance, StepRun $run, WorkflowEscalation $escalation, Node $node, Graph $graph, StepType $type, Throwable $exception): void
    {
        $failure = $this->exceptions->map($exception);
        $policy = RetryPolicies::resolve($node, $graph, $type);

        $escalation->attempts++;
        $escalation->error_code = substr($failure->code, 0, 64);
        $escalation->error_message = $failure->message;

        if ($failure->retryable && $escalation->attempts < $policy->maxAttempts) {
            $escalation->status = EscalationStatus::Pending;
            $escalation->claim_token = null;
            $escalation->due_at = Clock::now()->addSeconds(RetryPolicies::delay($policy, $escalation->attempts + 1));
            $escalation->save();

            return;
        }

        $escalation->status = EscalationStatus::Failed;
        $escalation->save();

        Events::dispatch(new EscalationFailed($instance, $run, $escalation->level, $failure->code, $instance, Runtime::actor($instance)));
    }

    /**
     * The visit's live attempt (running or waiting), whatever attempt the escalation was timed from.
     */
    private function liveRun(WorkflowEscalation $escalation): ?StepRun
    {
        $first = $escalation->stepRun;

        if (! $first instanceof StepRun) {
            return null;
        }

        $model = WorkflowModels::stepRun();
        $run = $model::query()
            ->where('instance_id', $first->instance_id)
            ->where('node_id', $first->node_id)
            ->where('visit', $first->visit)
            ->whereIn('status', [StepRunStatus::Running->value, StepRunStatus::Waiting->value])
            ->orderByDesc('attempt')
            ->lockForUpdate()
            ->first();

        return $run instanceof StepRun ? $run : null;
    }

    private function firstRun(WorkflowEscalation $escalation): StepRun
    {
        $run = $escalation->stepRun;

        return $run instanceof StepRun ? $run : throw StepFailedException::because(FailureCode::StepMissing, Messages::get('failures.version_missing'));
    }
}
