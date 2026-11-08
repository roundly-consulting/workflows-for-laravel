<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Wake\WakeAction;
use RoundlyConsulting\Workflows\Bridges\OpeningHours\OpeningHoursBridge;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\DoctorLevel;
use RoundlyConsulting\Workflows\Enums\EscalationStatus;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Schema\SchemaValidator;
use RoundlyConsulting\Workflows\Traits\HasWorkflows;
use Throwable;

/**
 * The checks behind `workflows:doctor`: installation (key types, queue, cache, wake, bridges),
 * the catalogue (every host config schema), active versions (adapters, unregistered step types,
 * calendars, opening-hours owners, condition waits on subjects without HasWorkflows), event
 * mappings, the webhook route, and the running system (stuck instances, the wake backlog, recent
 * failures).
 *
 * @internal
 */
final readonly class Doctor
{
    public function __construct(
        private StepCatalogue $catalogue,
        private SubjectRegistry $subjects,
        private CalendarRegistry $calendars,
        private Repository $cache,
        private ConfigRepository $config,
        private EventMappings $mappings,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        return [
            ...$this->keyTypes(),
            ...$this->queue(),
            ...$this->wake(),
            new DoctorFinding(DoctorLevel::Ok, 'bridges', Bridges::installed() === [] ? 'none' : implode(', ', Bridges::installed())),
            ...$this->catalogue(),
            ...$this->versions(),
            ...$this->events(),
            ...$this->webhooks(),
            ...$this->stuck(),
            ...$this->backlog(),
            $this->failures(),
        ];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function keyTypes(): array
    {
        $findings = [];

        try {
            $this->subjects->all();
            $findings[] = new DoctorFinding(DoctorLevel::Ok, 'key types', sprintf('subjects %s, owners %s, users %s', WorkflowsConfig::subjectKeyType()->value, WorkflowsConfig::ownerKeyType()->value, WorkflowsConfig::keyType()->value));
        } catch (Throwable $exception) {
            $findings[] = new DoctorFinding(DoctorLevel::Fail, 'key types', $exception->getMessage());
        }

        if (Bridges::available('teams')) {
            $team = $this->config->get('teams.models.team');

            if (is_string($team) && is_subclass_of($team, Model::class)) {
                try {
                    KeyTypes::assertModel('owner', $team);
                } catch (Throwable $exception) {
                    $findings[] = new DoctorFinding(DoctorLevel::Fail, 'owner key type', $exception->getMessage());
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function queue(): array
    {
        if (! WorkflowsConfig::queueEnabled()) {
            return [new DoctorFinding(DoctorLevel::Warn, 'queue', 'Queue off: steps run inline in the request that starts them.')];
        }

        $connection = WorkflowsConfig::queueConnection() ?? $this->config->get('queue.default');
        $connection = is_string($connection) ? $connection : 'sync';
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');
        $retryAfter = $this->config->get('queue.connections.'.$connection.'.retry_after');
        $timeout = max(30, (int) WorkflowsConfig::lease()->totalSeconds - 30);
        $findings = [];

        if ($driver === 'sync') {
            $findings[] = new DoctorFinding(DoctorLevel::Warn, 'queue', 'The queue connection is sync: steps run inside the request.');
        }

        if (is_numeric($retryAfter) && (int) $retryAfter <= $timeout) {
            $findings[] = new DoctorFinding(DoctorLevel::Fail, 'queue timeout', sprintf('retry_after (%ds) must be longer than the step job timeout (%ds), or a running step is delivered twice.', (int) $retryAfter, $timeout));
        }

        if (! function_exists('pcntl_signal')) {
            $findings[] = new DoctorFinding(DoctorLevel::Warn, 'pcntl', 'Without pcntl the worker cannot enforce the job timeout: a handler that outlives its lease may overlap its own retry.');
        }

        $store = $this->config->get('cache.default');
        $storeDriver = is_string($store) ? $this->config->get('cache.stores.'.$store.'.driver') : null;

        if (in_array($storeDriver, ['array', 'file', null], true)) {
            $findings[] = new DoctorFinding(DoctorLevel::Warn, 'cache', 'onOneServer() needs a cache store shared by every server (redis, database, memcached, dynamodb).');
        }

        return $findings === [] ? [new DoctorFinding(DoctorLevel::Ok, 'queue', $connection)] : $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function wake(): array
    {
        $last = $this->cache->get(WakeAction::LAST_RUN);

        if (! is_string($last)) {
            return [new DoctorFinding(DoctorLevel::Warn, 'wake', 'workflows:wake has not run yet: schedule it every minute.')];
        }

        return CarbonImmutable::parse($last)->lessThan(Clock::now()->subMinutes(5))
            ? [new DoctorFinding(DoctorLevel::Warn, 'wake', 'workflows:wake last ran at '.$last.': check the scheduler.')]
            : [new DoctorFinding(DoctorLevel::Ok, 'wake', $last)];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function catalogue(): array
    {
        try {
            $types = $this->catalogue->all();
        } catch (Throwable $exception) {
            return [new DoctorFinding(DoctorLevel::Fail, 'step types', $exception->getMessage())];
        }

        $findings = [];

        foreach ($types as $key => $type) {
            try {
                SchemaValidator::assertSupported($type->configSchema(), $key);
            } catch (Throwable $exception) {
                $findings[] = new DoctorFinding(DoctorLevel::Fail, 'config schema', $exception->getMessage());
            }
        }

        return [new DoctorFinding(DoctorLevel::Ok, 'step types', (string) count($types)), ...$findings];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function versions(): array
    {
        $findings = [];
        $definitionModel = WorkflowModels::definition();

        foreach ($definitionModel::query()->where('status', DefinitionStatus::Active->value)->with('currentVersion')->get() as $definition) {
            $version = $definition->currentVersion;

            if (! $version instanceof WorkflowVersion || $version->status !== VersionStatus::Published) {
                continue;
            }

            $owner = Definitions::owner($definition);

            foreach ($version->parsedGraph()->nodes as $node) {
                $package = $this->catalogue->missingAdapter($node->type);

                if ($package !== null) {
                    $findings[] = new DoctorFinding(DoctorLevel::Fail, 'adapter', sprintf('[%s] uses %s, which needs %s.', $definition->key, $node->type, $package));
                } elseif (! $this->catalogue->has($node->type)) {
                    $findings[] = new DoctorFinding(DoctorLevel::Fail, 'step types', sprintf('[%s] uses the step type %s, which is no longer registered.', $definition->key, $node->type));
                }

                if ($node->type === 'wait' && is_array($node->config['condition'] ?? null)) {
                    $findings = [...$findings, ...$this->conditionWaits($definition->key, $definition->subject_key)];
                }
            }

            $calendar = $version->settings['calendar'] ?? null;

            if (is_string($calendar) && str_starts_with($calendar, 'opening_hours:') && $owner !== null && Bridges::available('opening-hours') && ! OpeningHoursBridge::isOwner($owner)) {
                $findings[] = new DoctorFinding(DoctorLevel::Fail, 'opening hours', sprintf('[%s] uses %s, but its owner %s does not use HasOpeningHours.', $definition->key, $calendar, class_basename($owner)));
            } elseif (is_string($calendar) && ! $this->calendars->has($calendar, $owner)) {
                $findings[] = new DoctorFinding(DoctorLevel::Fail, 'calendar', sprintf('[%s] uses the missing calendar %s.', $definition->key, $calendar));
            }

            if ($version->actor_mode === ActorMode::OnBehalfOf && $version->trigger_type === TriggerType::Event) {
                foreach ($this->mappings->all() as $class => $mapping) {
                    // A self-describing event names its own user.
                    if (! $mapping->selfDescribing() && $mapping->key === $version->trigger_key && $mapping->user === null) {
                        $findings[] = new DoctorFinding(DoctorLevel::Warn, 'events', sprintf('[%s] runs on behalf of a user, but %s maps no user.', $definition->key, class_basename($class)));
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function events(): array
    {
        $findings = [];

        foreach ($this->mappings->duplicates() as $class) {
            $findings[] = new DoctorFinding(DoctorLevel::Warn, 'events', sprintf('%s is mapped more than once: the last mapping wins.', class_basename($class)));
        }

        foreach ($this->mappings->all() as $class => $mapping) {
            if (! class_exists($class)) {
                $findings[] = new DoctorFinding(DoctorLevel::Fail, 'events', sprintf('%s does not exist.', class_basename($class)));

                continue;
            }

            $selfDescribing = is_subclass_of($class, WorkflowEvent::class);

            if ($mapping->selfDescribing() && ! $selfDescribing) {
                $findings[] = new DoctorFinding(DoctorLevel::Fail, 'events', sprintf('%s is mapped as self-describing (*) but is not a WorkflowEvent.', class_basename($class)));
            } elseif (! $selfDescribing && $mapping->idempotencyKey === null) {
                $findings[] = new DoctorFinding(DoctorLevel::Warn, 'events', sprintf('%s → %s carries no idempotency key: every event starts an instance.', class_basename($class), $mapping->key));
            }
        }

        return $findings;
    }

    /**
     * A condition wait re-checks when its subject changes only through the HasWorkflows trait;
     * without it the wait wakes on its poll alone.
     *
     * @return list<DoctorFinding>
     */
    private function conditionWaits(string $key, ?string $subjectKey): array
    {
        $model = $subjectKey === null ? null : $this->subjects->find($subjectKey)?->model;

        if ($model === null || in_array(HasWorkflows::class, class_uses_recursive($model), true)) {
            return [];
        }

        return [new DoctorFinding(DoctorLevel::Warn, 'condition waits', sprintf('[%s] waits on a condition of %s, which does not use HasWorkflows: it re-checks on its poll only.', $key, class_basename($model)))];
    }

    /**
     * Running instances with nothing queued, running or waiting for longer than the lease.
     *
     * @return list<DoctorFinding>
     */
    private function stuck(): array
    {
        $instance = WorkflowModels::instance();
        $run = WorkflowModels::stepRun();
        $wait = WorkflowModels::wait();

        $count = $instance::query()
            ->where('status', InstanceStatus::Running->value)
            ->where('updated_at', '<', Clock::format(Clock::now()->sub(WorkflowsConfig::lease())))
            ->whereNotIn('id', $run::query()->whereIn('status', [StepRunStatus::Queued->value, StepRunStatus::Running->value])->select('instance_id'))
            ->whereNotIn('id', $wait::query()->where('status', WaitStatus::Pending->value)->select('instance_id'))
            ->count();

        return $count === 0 ? [] : [new DoctorFinding(DoctorLevel::Warn, 'stuck', sprintf('%d running instance(s) have nothing queued, running or waiting: inspect them with workflows:show.', $count))];
    }

    /**
     * Waits or escalations due more than five minutes ago: `workflows:wake` is not keeping up.
     *
     * @return list<DoctorFinding>
     */
    private function backlog(): array
    {
        $before = Clock::format(Clock::now()->subMinutes(5));
        $wait = WorkflowModels::wait();
        $escalation = WorkflowModels::escalation();

        $count = $wait::query()->where('status', WaitStatus::Pending->value)->whereNotNull('due_at')->where('due_at', '<', $before)->count()
            + $escalation::query()->where('status', EscalationStatus::Pending->value)->where('due_at', '<', $before)->count();

        return $count === 0 ? [] : [new DoctorFinding(DoctorLevel::Warn, 'wake backlog', sprintf('%d wait(s) or escalation(s) are overdue by more than five minutes: check that workflows:wake runs every minute.', $count))];
    }

    private function failures(): DoctorFinding
    {
        $instance = WorkflowModels::instance();
        $count = $instance::query()
            ->where('status', InstanceStatus::Failed->value)
            ->where('ended_at', '>', Clock::format(Clock::now()->subDay()))
            ->count();

        return new DoctorFinding(DoctorLevel::Ok, 'failures', sprintf('%d instance(s) failed in the last 24 hours.', $count));
    }

    /**
     * @return list<DoctorFinding>
     */
    private function webhooks(): array
    {
        if (! WorkflowsConfig::webhooksEnabled()) {
            return [];
        }

        return WorkflowsConfig::webhookVerifyMiddleware() === []
            ? [new DoctorFinding(DoctorLevel::Fail, 'webhooks', 'The webhook route is enabled without workflows.webhooks.verify_middleware: it stays unregistered.')]
            : [new DoctorFinding(DoctorLevel::Ok, 'webhooks', implode(', ', WorkflowsConfig::webhookVerifyMiddleware()))];
    }
}
