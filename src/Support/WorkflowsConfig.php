<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonInterval;
use DateTimeZone;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Support\Config;
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
    // Key types --------------------------------------------------------------------------

    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('workflows.key_type');
    }

    public static function ownerKeyType(): KeyType
    {
        return self::blank(config('workflows.owner_key_type'))
            ? self::keyType()
            : KeyType::fromConfig('workflows.owner_key_type');
    }

    public static function subjectKeyType(): KeyType
    {
        return self::blank(config('workflows.subject_key_type'))
            ? self::keyType()
            : KeyType::fromConfig('workflows.subject_key_type');
    }

    public static function primaryKeyType(): KeyType
    {
        return KeyType::fromConfig('workflows.primary_key_type');
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
            'definitions' => ['workflows.tables.definitions', Config::string('workflows.tables.definitions', 'workflow_definitions')],
            'versions' => ['workflows.tables.versions', Config::string('workflows.tables.versions', 'workflow_versions')],
            'instances' => ['workflows.tables.instances', Config::string('workflows.tables.instances', 'workflow_instances')],
            'step_runs' => ['workflows.tables.step_runs', Config::string('workflows.tables.step_runs', 'workflow_step_runs')],
            'waits' => ['workflows.tables.waits', Config::string('workflows.tables.waits', 'workflow_waits')],
            'escalations' => ['workflows.tables.escalations', Config::string('workflows.tables.escalations', 'workflow_escalations')],
            'idempotency_keys' => ['workflows.tables.idempotency_keys', Config::string('workflows.tables.idempotency_keys', 'workflow_idempotency_keys')],
            'dry_runs' => ['workflows.tables.dry_runs', Config::string('workflows.tables.dry_runs', 'workflow_dry_runs')],
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
        return Config::boolean('workflows.queue.enabled', true);
    }

    public static function queueConnection(): ?string
    {
        return self::optionalString('workflows.queue.connection', config('workflows.queue.connection'));
    }

    public static function queueName(): ?string
    {
        return self::optionalString('workflows.queue.queue', config('workflows.queue.queue'));
    }

    // Registries -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function steps(): array
    {
        return Config::list('workflows.steps', []);
    }

    /**
     * @return list<string>
     */
    public static function subjects(): array
    {
        return Config::list('workflows.subjects', []);
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
        $timezone = self::optionalString('workflows.timezone', config('workflows.timezone'));

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
        return Config::integer('workflows.wake.batch', 500, min: 1, max: 10000);
    }

    public static function claimTtl(): CarbonInterval
    {
        return self::duration('workflows.wake.claim_ttl', config('workflows.wake.claim_ttl'), 'PT10M', minSeconds: 60, maxSeconds: 86400);
    }

    public static function retryDefault(): RetryPolicy
    {
        $attempts = Config::integer('workflows.retry.default.max_attempts', 3, min: 1, max: self::maxAttemptsCap());
        $backoff = self::intList('workflows.retry.default.backoff', config('workflows.retry.default.backoff'), [30, 120, 600], 0, 86400);
        $jitter = Config::float('workflows.retry.default.jitter', 0.1, min: 0.0, max: 1.0);

        return new RetryPolicy($attempts, $backoff, $jitter);
    }

    public static function maxAttemptsCap(): int
    {
        return Config::integer('workflows.retry.max_attempts_cap', 10, min: 1, max: 100);
    }

    // Limits -----------------------------------------------------------------------------

    public static function maxSteps(): int
    {
        return Config::integer('workflows.limits.max_steps', 200, min: 2, max: 100000);
    }

    public static function maxDepth(): int
    {
        return Config::integer('workflows.limits.max_depth', 3, min: 0, max: 50);
    }

    /**
     * Null when the limit is `off`. Read locally: Config::integer() has no "off" sentinel.
     */
    public static function maxStartsPerMinute(): ?int
    {
        $raw = config('workflows.limits.max_starts_per_minute');

        if (is_string($raw) && in_array(strtolower(trim($raw)), ['off', 'none', 'null'], true)) {
            return null;
        }

        return Config::integer('workflows.limits.max_starts_per_minute', 60, min: 1, max: 1000000);
    }

    public static function maxLoopIterations(): int
    {
        return Config::integer('workflows.limits.max_loop_iterations', 1000, min: 1, max: 100000);
    }

    public static function graphMaxNodes(): int
    {
        return Config::integer('workflows.graph.max_nodes', 500, min: 2, max: 10000);
    }

    public static function graphMaxEdges(): int
    {
        return Config::integer('workflows.graph.max_edges', 2000, min: 1, max: 100000);
    }

    public static function graphMaxBytes(): int
    {
        return Config::integer('workflows.graph.max_bytes', 524288, min: 1024, max: 16777216);
    }

    public static function conditionsMaxDepth(): int
    {
        return Config::integer('workflows.conditions.max_depth', 6, min: 1, max: 32);
    }

    public static function conditionsMaxClauses(): int
    {
        return Config::integer('workflows.conditions.max_clauses', 100, min: 1, max: 10000);
    }

    public static function stepOutputMaxBytes(): int
    {
        return Config::integer('workflows.steps_output.max_bytes', 65536, min: 256, max: 16777216);
    }

    public static function stepInputMaxBytes(): int
    {
        return Config::integer('workflows.steps_input.max_bytes', 65536, min: 1024, max: 1048576);
    }

    public static function escalationsMaxLevels(): int
    {
        return Config::integer('workflows.escalations.max_levels', 5, min: 1, max: 20);
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

    public static function authorizer(): ?string
    {
        return self::optionalString('workflows.authorization.authorizer', config('workflows.authorization.authorizer'));
    }

    /**
     * @return array<string, string>
     */
    public static function abilities(): array
    {
        return self::stringMap('workflows.authorization.abilities', config('workflows.authorization.abilities'));
    }

    public static function systemUserResolver(): ?string
    {
        return self::optionalString('workflows.actor.system_user', config('workflows.actor.system_user'));
    }

    public static function ownerContext(): ?string
    {
        return self::optionalString('workflows.owners.context', config('workflows.owners.context'));
    }

    public static function ownerMembership(): ?string
    {
        return self::optionalString('workflows.owners.membership', config('workflows.owners.membership'));
    }

    public static function partiesUserModel(): ?string
    {
        $model = self::optionalString('workflows.parties.user_model', config('workflows.parties.user_model'));

        if ($model !== null) {
            return $model;
        }

        $fallback = config('auth.providers.users.model');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    public static function partiesRoles(): ?string
    {
        return self::optionalString('workflows.parties.roles', config('workflows.parties.roles'));
    }

    public static function transitionAdapter(): ?string
    {
        return self::optionalString('workflows.adapters.transition', config('workflows.adapters.transition'));
    }

    public static function webhooksAdapter(): ?string
    {
        return self::optionalString('workflows.adapters.webhooks', config('workflows.adapters.webhooks'));
    }

    // Business time ----------------------------------------------------------------------

    /**
     * @return list<int>
     */
    public static function businessWeekdays(): array
    {
        return array_values(array_unique(self::intList('workflows.business_time.weekdays', config('workflows.business_time.weekdays'), [1, 2, 3, 4, 5], 1, 7)));
    }

    public static function businessMaxSearchDays(): int
    {
        return Config::integer('workflows.business_time.max_search_days', 366, min: 7, max: 3660);
    }

    public static function calendarOwnerResolver(): ?string
    {
        return self::optionalString('workflows.business_time.calendar_owner', config('workflows.business_time.calendar_owner'));
    }

    public static function approvalExpiryGrace(): CarbonInterval
    {
        return self::duration('workflows.approval.expiry_grace', config('workflows.approval.expiry_grace'), 'PT5M', minSeconds: 0, maxSeconds: 86400);
    }

    public static function subjectsOnDeleted(): string
    {
        return Config::oneOf('workflows.subjects_on_deleted', ['cancel', 'keep'], 'cancel');
    }

    public static function dryRunLog(): bool
    {
        return Config::boolean('workflows.dry_run.log', false);
    }

    // Webhooks ---------------------------------------------------------------------------

    public static function webhooksEnabled(): bool
    {
        return Config::boolean('workflows.webhooks.enabled', false);
    }

    public static function webhooksPrefix(): string
    {
        return trim(Config::string('workflows.webhooks.prefix', 'workflows'), '/');
    }

    /**
     * @return list<string>
     */
    public static function webhooksMiddleware(): array
    {
        return Config::list('workflows.webhooks.middleware', ['api']);
    }

    /**
     * @return list<string>
     */
    public static function webhookVerifyMiddleware(): array
    {
        return array_values(array_filter(Config::list('workflows.webhooks.verify_middleware', []), static fn (string $item): bool => trim($item) !== ''));
    }

    public static function webhooksMaxBytes(): int
    {
        return Config::integer('workflows.webhooks.max_bytes', 262144, min: 256, max: 16777216);
    }

    // Resources --------------------------------------------------------------------------

    /**
     * @return class-string<JsonResource>
     */
    public static function ownerResource(): string
    {
        return self::resource('workflows.resources.owner', config('workflows.resources.owner'), WorkflowOwnerResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function subjectResource(): string
    {
        return self::resource('workflows.resources.subject', config('workflows.resources.subject'), WorkflowSubjectResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function userResource(): string
    {
        return self::resource('workflows.resources.user', config('workflows.resources.user'), WorkflowUserResource::class);
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
     * A class-string from config that must implement the contract, or null when blank.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return class-string<T>|null
     */
    public static function implementation(string $key, ?string $class, string $contract): ?string
    {
        if ($class === null) {
            return null;
        }

        if (! class_exists($class) || ! is_subclass_of($class, $contract)) {
            throw InvalidWorkflowsConfigurationException::notAnImplementation($key, $contract, $class);
        }

        return $class;
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
     * A list of integers (an array of ints or integer strings, or an env comma list).
     *
     * @param  list<int>  $default
     * @return list<int>
     */
    public static function intList(string $key, mixed $raw, array $default, int $min, int $max): array
    {

        if (self::blank($raw)) {
            return $default;
        }

        $items = is_string($raw) ? array_map(trim(...), explode(',', $raw)) : $raw;

        if (! is_array($items) || $items === []) {
            throw InvalidWorkflowsConfigurationException::invalidMap($key, sprintf('a non-empty list of integers between %d and %d', $min, $max));
        }

        $list = [];

        foreach ($items as $item) {
            if (is_string($item) && preg_match('/^-?\d+$/', $item) === 1) {
                $item = (int) $item;
            }

            if (! is_int($item) || $item < $min || $item > $max) {
                throw InvalidWorkflowsConfigurationException::invalidMap($key, sprintf('a non-empty list of integers between %d and %d', $min, $max));
            }

            $list[] = $item;
        }

        return $list;
    }

    private static function optionalString(string $key, mixed $value): ?string
    {

        if (self::blank($value)) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidWorkflowsConfigurationException::invalidMap($key, 'a string');
        }

        return trim($value);
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(string $key, mixed $raw): array
    {

        if (self::blank($raw)) {
            return [];
        }

        if (! is_array($raw)) {
            throw InvalidWorkflowsConfigurationException::invalidMap($key, 'a map of strings');
        }

        $map = [];

        foreach ($raw as $name => $value) {
            if (! is_string($name) || ! is_string($value) || $value === '') {
                throw InvalidWorkflowsConfigurationException::invalidMap($key, 'a map of strings');
            }

            $map[$name] = $value;
        }

        return $map;
    }

    /**
     * @param  class-string<JsonResource>  $default
     * @return class-string<JsonResource>
     */
    private static function resource(string $key, mixed $resource, string $default): string
    {

        if (self::blank($resource)) {
            return $default;
        }

        if (! is_string($resource)
            || ! class_exists($resource)
            || ! is_subclass_of($resource, JsonResource::class)
            || ! (new ReflectionClass($resource))->isInstantiable()) {
            throw InvalidWorkflowsConfigurationException::notAnImplementation($key, JsonResource::class, $resource);
        }

        return $resource;
    }
}
