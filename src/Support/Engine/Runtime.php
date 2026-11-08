<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Graph\Graph;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Graph\Node;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\TriggerInputs;
use RoundlyConsulting\Workflows\Support\ValueScope;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Builds what a step runs with: the version timezone, the subject and owner (loaded fresh, inside
 * the owner context), the actor, the value scope and the StepContext.
 *
 * @internal
 */
final class Runtime
{
    public static function timezone(WorkflowVersion $version): string
    {
        $timezone = $version->settings['timezone'] ?? null;

        return is_string($timezone) && WorkflowsConfig::isTimezone($timezone) ? $timezone : WorkflowsConfig::timezone();
    }

    public static function subject(WorkflowInstance $instance): ?Model
    {
        return self::load($instance->subject_type, $instance->subject_id);
    }

    public static function owner(WorkflowInstance $instance): ?Model
    {
        return self::load($instance->owner_type, $instance->owner_id);
    }

    public static function starter(WorkflowInstance $instance): ?Model
    {
        return self::load($instance->starter_type, $instance->starter_id);
    }

    public static function load(?string $type, int|string|null $id): ?Model
    {
        if ($type === null || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $model = $class::query()->find($id);

        return $model instanceof Model ? $model : null;
    }

    public static function actor(WorkflowInstance $instance, ?Model $starter = null): Actor
    {
        if ($instance->actor_mode === ActorMode::OnBehalfOf) {
            $starter ??= self::starter($instance);

            return $starter === null ? Actor::system() : Actor::onBehalfOf($starter);
        }

        return Actor::system(self::systemUser());
    }

    public static function systemUser(): ?Model
    {
        $resolver = WorkflowsConfig::systemUserResolver();

        if ($resolver === null || ! class_exists($resolver)) {
            return null;
        }

        $instance = app($resolver);
        $user = is_callable($instance) ? $instance() : null;

        return $user instanceof Model ? $user : null;
    }

    /**
     * @return Closure(string): (array<string, mixed>|null)
     */
    public static function outputs(WorkflowInstance $instance): Closure
    {
        return static function (string $node) use ($instance): ?array {
            $model = WorkflowModels::stepRun();
            $run = $model::query()
                ->where('instance_id', $instance->getKey())
                ->where('node_id', $node)
                ->where('status', StepRunStatus::Completed->value)
                ->orderByDesc('id')
                ->first();

            return $run instanceof StepRun ? $run->output : null;
        };
    }

    public static function scope(WorkflowInstance $instance, WorkflowVersion $version, ?Model $subject, ?Model $owner, ?CarbonImmutable $now = null, ?Model $starter = null): ValueScope
    {
        $registry = app(SubjectRegistry::class);
        $payload = $instance->trigger_payload ?? [];
        $input = is_array($payload['input'] ?? null) ? GraphParser::stringKeyed($payload['input']) : [];
        $trigger = is_array($payload['trigger'] ?? null) ? GraphParser::stringKeyed($payload['trigger']) : [];

        return new ValueScope(
            subject: $subject,
            subjectType: $instance->subject_key !== null ? $registry->find($instance->subject_key) : null,
            owner: $owner,
            ownerType: $owner !== null ? $registry->forModel($owner) : null,
            input: $input,
            trigger: $trigger,
            outputs: self::outputs($instance),
            now: $now ?? Clock::now(),
            timezone: self::timezone($version),
            starter: $starter ?? self::starter($instance),
            inputTypes: TriggerInputs::declared($version->trigger),
        );
    }

    public static function context(
        WorkflowInstance $instance,
        StepRun $run,
        WorkflowVersion $version,
        Graph $graph,
        Node $node,
        ?Model $subject,
        ?Model $owner,
        ?Signal $signal = null,
    ): StepContext {
        $starter = self::starter($instance);

        return new StepContext(
            instance: $instance,
            stepRun: $run,
            node: $node,
            graph: $graph,
            version: $version,
            actor: self::actor($instance, $starter),
            scope: self::scope($instance, $version, $subject, $owner, null, $starter),
            signal: $signal,
            simulating: false,
            definitionKey: $instance->definition?->key,
        );
    }
}
