<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonInterval;
use Closure;
use DateTimeZone;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;
use RoundlyConsulting\Workflows\Contracts\AuthorizesSteps;
use RoundlyConsulting\Workflows\Contracts\ChecksOwnerMembership;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;
use RoundlyConsulting\Workflows\Contracts\ResolvesRoles;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Contracts\TransitionAdapter;
use RoundlyConsulting\Workflows\DataTransferObjects\EventMapping;
use RoundlyConsulting\Workflows\DataTransferObjects\RetryPolicy;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Http\Resources\WorkflowOwnerResource;
use RoundlyConsulting\Workflows\Http\Resources\WorkflowSubjectResource;
use RoundlyConsulting\Workflows\Http\Resources\WorkflowUserResource;
use Throwable;

/**
 * The only place that reads `workflows.*`. Every reader is strict: a typo throws naming the
 * key, a blank value takes the default.
 *
 * @internal
 */
final class WorkflowsConfig
{
    /**
     * Every toolkit reader goes through here, so each configuration error — the toolkit's
     * readers and the local ones alike — is an InvalidWorkflowsConfigurationException a host can
     * catch as one class.
     */
    private static function reader(): ConfigValidator
    {
        return Config::using(InvalidWorkflowsConfigurationException::class);
    }

    /**
     * A toolkit reader that has no `using()` form (key types, the model seam), re-thrown as the
     * package's configuration exception with the same message.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function rethrown(Closure $read): mixed
    {
        try {
            return $read();
        } catch (InvalidConfigurationException $exception) {
            throw new InvalidWorkflowsConfigurationException($exception->getMessage(), 0, $exception);
        }
    }

    // Key types --------------------------------------------------------------------------

    public static function keyType(): KeyType
    {
        return self::rethrown(static fn (): KeyType => KeyType::fromConfig('workflows.key_type'));
    }

    public static function ownerKeyType(): KeyType
    {
        return self::blank(config('workflows.owner_key_type'))
            ? self::keyType()
            : self::rethrown(static fn (): KeyType => KeyType::fromConfig('workflows.owner_key_type'));
    }

    public static function subjectKeyType(): KeyType
    {
        return self::blank(config('workflows.subject_key_type'))
            ? self::keyType()
            : self::rethrown(static fn (): KeyType => KeyType::fromConfig('workflows.subject_key_type'));
    }

    public static function primaryKeyType(): KeyType
    {
        return self::rethrown(static fn (): KeyType => KeyType::fromConfig('workflows.primary_key_type'));
    }

    /**
     * The config key that governs a morph role's key type.
     */
    public static function keyTypeKeyFor(string $role): string
    {
        return match ($role) {
            'owner' => self::blank(config('workflows.owner_key_type')) ? 'workflows.key_type' : 'workflows.owner_key_type',
            'subject' => self::blank(config('workflows.subject_key_type')) ? 'workflows.key_type' : 'workflows.subject_key_type',
            default => 'workflows.key_type',
        };
    }

    public static function keyTypeFor(string $role): KeyType
    {
        return match ($role) {
            'owner' => self::ownerKeyType(),
            'subject' => self::subjectKeyType(),
            default => self::keyType(),
        };
    }

    // Tables -----------------------------------------------------------------------------

    public static function table(string $name): string
    {
        [$key, $table] = match ($name) {
            'definitions' => ['workflows.tables.definitions', self::reader()->string('workflows.tables.definitions', 'workflow_definitions')],
            'versions' => ['workflows.tables.versions', self::reader()->string('workflows.tables.versions', 'workflow_versions')],
            'instances' => ['workflows.tables.instances', self::reader()->string('workflows.tables.instances', 'workflow_instances')],
            'step_runs' => ['workflows.tables.step_runs', self::reader()->string('workflows.tables.step_runs', 'workflow_step_runs')],
            'waits' => ['workflows.tables.waits', self::reader()->string('workflows.tables.waits', 'workflow_waits')],
            'escalations' => ['workflows.tables.escalations', self::reader()->string('workflows.tables.escalations', 'workflow_escalations')],
            'idempotency_keys' => ['workflows.tables.idempotency_keys', self::reader()->string('workflows.tables.idempotency_keys', 'workflow_idempotency_keys')],
            'dry_runs' => ['workflows.tables.dry_runs', self::reader()->string('workflows.tables.dry_runs', 'workflow_dry_runs')],
            default => throw InvalidWorkflowsConfigurationException::invalidTable('workflows.tables.'.$name, $name),
        };

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $table) !== 1) {
            throw InvalidWorkflowsConfigurationException::invalidTable($key, $table);
        }

        return $table;
    }

    // Queue ------------------------------------------------------------------------------

    public static function queueEnabled(): bool
    {
        return self::reader()->boolean('workflows.queue.enabled', true);
    }

    public static function queueConnection(): ?string
    {
        return self::reader()->optionalString('workflows.queue.connection');
    }

    public static function queueName(): ?string
    {
        return self::reader()->optionalString('workflows.queue.queue');
    }

    // Registries -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function steps(): array
    {
        return self::reader()->list('workflows.steps', []);
    }

    /**
     * @return list<string>
     */
    public static function subjects(): array
    {
        return self::reader()->list('workflows.subjects', []);
    }

    /**
     * The configured event map: event class => its mapping (a bare string is the trigger key).
     *
     * @return array<string, EventMapping>
     */
    public static function events(): array
    {
        $key = 'workflows.events';
        $expected = 'a map of event class => trigger key or {key, subject, user, payload, idempotency_key}';
        $raw = config('workflows.events');

        if (self::blank($raw)) {
            return [];
        }

        if (! is_array($raw)) {
            throw InvalidWorkflowsConfigurationException::invalidMap($key, $expected);
        }

        $events = [];

        foreach ($raw as $class => $mapping) {
            if (! is_string($class) || $class === '') {
                throw InvalidWorkflowsConfigurationException::invalidMap($key, 'a map keyed by event class');
            }

            if (is_string($mapping) && $mapping !== '') {
                $events[$class] = new EventMapping($mapping);

                continue;
            }

            if (! is_array($mapping) || ! is_string($mapping['key'] ?? null) || $mapping['key'] === '') {
                throw InvalidWorkflowsConfigurationException::invalidMap($key, $expected);
            }

            $events[$class] = new EventMapping(
                $mapping['key'],
                self::name($mapping['subject'] ?? null),
                self::name($mapping['user'] ?? null),
                self::eventPayload($mapping['payload'] ?? null, $key.'.'.$class.'.payload'),
                self::name($mapping['idempotency_key'] ?? null),
            );
        }

        return $events;
    }

    /**
     * An event mapping's payload: a list of event property names, or one method name.
     *
     * @return list<string>|string|null
     */
    public static function eventPayload(mixed $value, string $key): array|string|null
    {
        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        $names = [];

        foreach (is_array($value) && array_is_list($value) ? $value : [null] as $name) {
            if (! is_string($name) || $name === '') {
                throw InvalidWorkflowsConfigurationException::invalidMap($key, 'a list of event property names, or one method name');
            }

            $names[] = $name;
        }

        return $names;
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function timezone(): string
    {
        $timezone = self::reader()->optionalString('workflows.timezone');

        if ($timezone === null) {
            $app = config('app.timezone');

            return is_string($app) && $app !== '' ? $app : 'UTC';
        }

        if (! self::isTimezone($timezone)) {
            throw InvalidWorkflowsConfigurationException::invalidTimezone('workflows.timezone', $timezone);
        }

        return $timezone;
    }

    public static function isTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return false;
        }

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) || $timezone === 'UTC';
    }

    // Engine -----------------------------------------------------------------------------

    public static function lease(): CarbonInterval
    {
        return self::duration('workflows.engine.lease', config('workflows.engine.lease'), 'PT10M', minSeconds: 60, maxSeconds: 86400);
    }

    public static function requeueAfter(): CarbonInterval
    {
        return self::duration('workflows.engine.requeue_after', config('workflows.engine.requeue_after'), 'PT5M', minSeconds: 60, maxSeconds: 86400);
    }

    public static function wakeBatch(): int
    {
        return self::reader()->integer('workflows.wake.batch', 500, min: 1, max: 10000);
    }

    public static function claimTtl(): CarbonInterval
    {
        return self::duration('workflows.wake.claim_ttl', config('workflows.wake.claim_ttl'), 'PT10M', minSeconds: 60, maxSeconds: 86400);
    }

    public static function retryDefault(): RetryPolicy
    {
        $attempts = self::reader()->integer('workflows.retry.default.max_attempts', 3, min: 1, max: self::maxAttemptsCap());
        $backoff = self::reader()->integerList('workflows.retry.default.backoff', [30, 120, 600], min: 0, max: 86400);
        $jitter = self::reader()->float('workflows.retry.default.jitter', 0.1, min: 0.0, max: 1.0);

        return new RetryPolicy($attempts, $backoff, $jitter);
    }

    public static function maxAttemptsCap(): int
    {
        return self::reader()->integer('workflows.retry.max_attempts_cap', 10, min: 1, max: 100);
    }

    // Limits -----------------------------------------------------------------------------

    public static function maxSteps(): int
    {
        return self::reader()->integer('workflows.limits.max_steps', 200, min: 2, max: 100000);
    }

    public static function maxDepth(): int
    {
        return self::reader()->integer('workflows.limits.max_depth', 3, min: 0, max: 50);
    }

    /**
     * Null when the limit is switched off (`off`, `none` or `null`).
     */
    public static function maxStartsPerMinute(): ?int
    {
        return self::reader()->optionalInteger('workflows.limits.max_starts_per_minute', 60, min: 1, max: 1000000, offWords: ['off', 'none', 'null']);
    }

    public static function maxLoopIterations(): int
    {
        return self::reader()->integer('workflows.limits.max_loop_iterations', 1000, min: 1, max: 100000);
    }

    public static function graphMaxNodes(): int
    {
        return self::reader()->integer('workflows.graph.max_nodes', 500, min: 2, max: 10000);
    }

    public static function graphMaxEdges(): int
    {
        return self::reader()->integer('workflows.graph.max_edges', 2000, min: 1, max: 100000);
    }

    public static function graphMaxBytes(): int
    {
        return self::reader()->integer('workflows.graph.max_bytes', 524288, min: 1024, max: 16777216);
    }

    public static function conditionsMaxDepth(): int
    {
        return self::reader()->integer('workflows.conditions.max_depth', 6, min: 1, max: 32);
    }

    public static function conditionsMaxClauses(): int
    {
        return self::reader()->integer('workflows.conditions.max_clauses', 100, min: 1, max: 10000);
    }

    public static function stepOutputMaxBytes(): int
    {
        return self::reader()->integer('workflows.steps_output.max_bytes', 65536, min: 256, max: 16777216);
    }

    public static function stepInputMaxBytes(): int
    {
        return self::reader()->integer('workflows.steps_input.max_bytes', 65536, min: 1024, max: 1048576);
    }

    public static function escalationsMaxLevels(): int
    {
        return self::reader()->integer('workflows.escalations.max_levels', 5, min: 1, max: 20);
    }

    public static function idempotencyTtl(): CarbonInterval
    {
        return self::duration('workflows.idempotency.ttl', config('workflows.idempotency.ttl'), 'P30D', minSeconds: 60, maxSeconds: 31622400);
    }

    public static function scheduleCatchUp(): CarbonInterval
    {
        return self::duration('workflows.schedule.catch_up', config('workflows.schedule.catch_up'), 'PT1H', minSeconds: 60, maxSeconds: 86400);
    }

    // Authorization, actor, owners, parties, adapters ------------------------------------

    /**
     * @return class-string<AuthorizesSteps>
     */
    public static function authorizer(): string
    {
        return self::implementation('workflows.authorization.authorizer', AuthorizesSteps::class, GateStepAuthorizer::class);
    }

    /**
     * @return array<string, string>
     */
    public static function abilities(): array
    {
        return self::reader()->stringMap('workflows.authorization.abilities');
    }

    public static function systemUserResolver(): ?string
    {
        return self::reader()->optionalString('workflows.actor.system_user');
    }

    /**
     * @return class-string<RestoresOwnerContext>|null
     */
    public static function ownerContext(): ?string
    {
        return self::implementation('workflows.owners.context', RestoresOwnerContext::class);
    }

    /**
     * @return class-string<ChecksOwnerMembership>|null
     */
    public static function ownerMembership(): ?string
    {
        return self::implementation('workflows.owners.membership', ChecksOwnerMembership::class);
    }

    public static function partiesUserModel(): ?string
    {
        $model = self::reader()->optionalString('workflows.parties.user_model');

        if ($model !== null) {
            return $model;
        }

        $fallback = config('auth.providers.users.model');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * @return class-string<ResolvesRoles>|null
     */
    public static function partiesRoles(): ?string
    {
        return self::implementation('workflows.parties.roles', ResolvesRoles::class);
    }

    /**
     * @return class-string<TransitionAdapter>|null
     */
    public static function transitionAdapter(): ?string
    {
        return self::implementation('workflows.adapters.transition', TransitionAdapter::class);
    }

    /**
     * @return class-string<DeliversWebhooks>|null
     */
    public static function webhooksAdapter(): ?string
    {
        return self::implementation('workflows.adapters.webhooks', DeliversWebhooks::class);
    }

    // Business time ----------------------------------------------------------------------

    /**
     * @return list<int>
     */
    public static function businessWeekdays(): array
    {
        return array_values(array_unique(self::reader()->integerList('workflows.business_time.weekdays', [1, 2, 3, 4, 5], min: 1, max: 7)));
    }

    public static function businessMaxSearchDays(): int
    {
        return self::reader()->integer('workflows.business_time.max_search_days', 366, min: 7, max: 3660);
    }

    public static function calendarOwnerResolver(): ?string
    {
        return self::reader()->optionalString('workflows.business_time.calendar_owner');
    }

    public static function approvalExpiryGrace(): CarbonInterval
    {
        return self::duration('workflows.approval.expiry_grace', config('workflows.approval.expiry_grace'), 'PT5M', minSeconds: 0, maxSeconds: 86400);
    }

    public static function subjectsOnDeleted(): string
    {
        return self::reader()->oneOf('workflows.subjects_on_deleted', ['cancel', 'keep'], 'cancel');
    }

    /**
     * Where `workflows:sync` and `workflows:export` read and write definition files: absolute, or
     * relative to the app's base path.
     */
    public static function syncPath(): string
    {
        $path = self::reader()->string('workflows.sync.path', 'resources/workflows');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public static function lifecycleEmitTransitions(): bool
    {
        return self::reader()->boolean('workflows.bridges.lifecycle.emit_transitions', false);
    }

    /**
     * The inbound handler alias the webhooks bridge registers, or null when it registers none.
     * A host that published its config before this key existed (Laravel merges the top level
     * only) keeps the default; null, false or `off` switches it off; a blank value is the default.
     */
    public static function webhooksHandlerAlias(): ?string
    {
        $bridges = config('workflows.bridges');

        if (! is_array($bridges) || ! is_array($bridges['webhooks'] ?? null) || ! array_key_exists('handler', $bridges['webhooks'])) {
            return 'workflows';
        }

        $alias = config('workflows.bridges.webhooks.handler');

        if ($alias === null || $alias === false || (is_string($alias) && strtolower(trim($alias)) === 'off')) {
            return null;
        }

        if ($alias === '') {
            return 'workflows';
        }

        // The webhooks package's own alias rule (`HandlerRegistry::register()`).
        if (! is_string($alias) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,99}$/D', $alias) !== 1) {
            throw InvalidWorkflowsConfigurationException::invalidMap('workflows.bridges.webhooks.handler', 'a handler alias (a-z, 0-9, _ . -), or null to register none');
        }

        return $alias;
    }

    public static function dryRunLog(): bool
    {
        return self::reader()->boolean('workflows.dry_run.log', false);
    }

    // Webhooks ---------------------------------------------------------------------------

    public static function webhooksEnabled(): bool
    {
        return self::reader()->boolean('workflows.webhooks.enabled', false);
    }

    public static function webhooksPrefix(): string
    {
        return trim(self::reader()->string('workflows.webhooks.prefix', 'workflows'), '/');
    }

    /**
     * @return list<string>
     */
    public static function webhooksMiddleware(): array
    {
        return self::reader()->list('workflows.webhooks.middleware', ['api']);
    }

    /**
     * @return list<string>
     */
    public static function webhookVerifyMiddleware(): array
    {
        return array_values(array_filter(self::reader()->list('workflows.webhooks.verify_middleware', []), static fn (string $item): bool => trim($item) !== ''));
    }

    public static function webhooksMaxBytes(): int
    {
        return self::reader()->integer('workflows.webhooks.max_bytes', 262144, min: 256, max: 16777216);
    }

    // Resources --------------------------------------------------------------------------

    /**
     * @return class-string<JsonResource>
     */
    public static function ownerResource(): string
    {
        return self::resource('workflows.resources.owner', WorkflowOwnerResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function subjectResource(): string
    {
        return self::resource('workflows.resources.subject', WorkflowSubjectResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function userResource(): string
    {
        return self::resource('workflows.resources.user', WorkflowUserResource::class);
    }

    // Prune ------------------------------------------------------------------------------

    public static function pruneInstancesAfter(): CarbonInterval
    {
        return self::duration('workflows.prune.instances_after', config('workflows.prune.instances_after'), 'P90D', minSeconds: 3600, maxSeconds: 315360000);
    }

    public static function pruneDryRunsAfter(): CarbonInterval
    {
        return self::duration('workflows.prune.dry_runs_after', config('workflows.prune.dry_runs_after'), 'P30D', minSeconds: 3600, maxSeconds: 315360000);
    }

    // Helpers ----------------------------------------------------------------------------

    /**
     * A class-string from config that implements the contract (instantiable), else the default.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @param  class-string<T>|null  $default
     * @return ($default is null ? class-string<T>|null : class-string<T>)
     */
    public static function implementation(string $key, string $contract, ?string $default = null): ?string
    {
        return self::reader()->implementation($key, $contract, $default);
    }

    public static function duration(string $key, mixed $raw, string $default, int $minSeconds, int $maxSeconds): CarbonInterval
    {
        $value = self::blank($raw) ? $default : $raw;

        if (! is_string($value)) {
            throw InvalidWorkflowsConfigurationException::invalidDuration($key, $value);
        }

        try {
            $interval = new CarbonInterval($value);
        } catch (Throwable) {
            throw InvalidWorkflowsConfigurationException::invalidDuration($key, $value);
        }

        $seconds = (int) $interval->totalSeconds;

        if ($seconds < $minSeconds || $seconds > $maxSeconds) {
            throw InvalidWorkflowsConfigurationException::durationOutOfRange($key, sprintf('between %ds and %ds', $minSeconds, $maxSeconds), $value);
        }

        return $interval;
    }

    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param  class-string<JsonResource>  $default
     * @return class-string<JsonResource>
     */
    private static function resource(string $key, string $default): string
    {
        return self::implementation($key, JsonResource::class, $default);
    }
}
