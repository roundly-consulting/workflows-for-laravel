<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Wake\WakeAction;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Workflows\Enums\ActorMode;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use Throwable;

/**
 * The checks behind `workflows:doctor`.
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
            new DoctorFinding('ok', 'bridges', Bridges::installed() === [] ? 'none' : implode(', ', Bridges::installed())),
            ...$this->catalogue(),
            ...$this->versions(),
            ...$this->events(),
            ...$this->webhooks(),
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
            $findings[] = new DoctorFinding('ok', 'key types', sprintf('subjects %s, owners %s, users %s', WorkflowsConfig::subjectKeyType()->value, WorkflowsConfig::ownerKeyType()->value, WorkflowsConfig::keyType()->value));
        } catch (Throwable $exception) {
            $findings[] = new DoctorFinding('fail', 'key types', $exception->getMessage());
        }

        if (Bridges::available('teams')) {
            $team = $this->config->get('teams.models.team');

            if (is_string($team) && is_subclass_of($team, Model::class)) {
                try {
                    KeyTypes::assertModel('owner', $team);
                } catch (Throwable $exception) {
                    $findings[] = new DoctorFinding('fail', 'owner key type', $exception->getMessage());
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
            return [new DoctorFinding('warn', 'queue', 'Queue off: steps run inline in the request that starts them.')];
        }

        $connection = WorkflowsConfig::queueConnection() ?? $this->config->get('queue.default');
        $connection = is_string($connection) ? $connection : 'sync';
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');
        $retryAfter = $this->config->get('queue.connections.'.$connection.'.retry_after');
        $timeout = max(30, (int) WorkflowsConfig::lease()->totalSeconds - 30);
        $findings = [];

        if ($driver === 'sync') {
            $findings[] = new DoctorFinding('warn', 'queue', 'The queue connection is sync: steps run inside the request.');
        }

        if (is_numeric($retryAfter) && (int) $retryAfter <= $timeout) {
            $findings[] = new DoctorFinding('fail', 'queue timeout', sprintf('retry_after (%ds) must be longer than the step job timeout (%ds), or a running step is delivered twice.', (int) $retryAfter, $timeout));
        }

        if (! function_exists('pcntl_signal')) {
            $findings[] = new DoctorFinding('warn', 'pcntl', 'Without pcntl the worker cannot enforce the job timeout: a handler that outlives its lease may overlap its own retry.');
        }

        $store = $this->config->get('cache.default');
        $storeDriver = is_string($store) ? $this->config->get('cache.stores.'.$store.'.driver') : null;

        if (in_array($storeDriver, ['array', 'file', null], true)) {
            $findings[] = new DoctorFinding('warn', 'cache', 'onOneServer() needs a cache store shared by every server (redis, database, memcached, dynamodb).');
        }

        return $findings === [] ? [new DoctorFinding('ok', 'queue', $connection)] : $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private function wake(): array
    {
        $last = $this->cache->get(WakeAction::LAST_RUN);

        if (! is_string($last)) {
            return [new DoctorFinding('warn', 'wake', 'workflows:wake has not run yet: schedule it every minute.')];
        }

        return CarbonImmutable::parse($last)->lessThan(Clock::now()->subMinutes(5))
            ? [new DoctorFinding('warn', 'wake', 'workflows:wake last ran at '.$last.': check the scheduler.')]
            : [new DoctorFinding('ok', 'wake', $last)];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function catalogue(): array
    {
        try {
            return [new DoctorFinding('ok', 'step types', (string) count($this->catalogue->all()))];
        } catch (Throwable $exception) {
            return [new DoctorFinding('fail', 'step types', $exception->getMessage())];
        }
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

            foreach ($version->parsedGraph()->nodes as $node) {
                $package = $this->catalogue->missingAdapter($node->type);

                if ($package !== null) {
                    $findings[] = new DoctorFinding('fail', 'adapter', sprintf('[%s] uses %s, which needs %s.', $definition->key, $node->type, $package));
                }
            }

            $calendar = $version->settings['calendar'] ?? null;

            if (is_string($calendar) && ! $this->calendars->has($calendar, Definitions::owner($definition))) {
                $findings[] = new DoctorFinding('fail', 'calendar', sprintf('[%s] uses the missing calendar %s.', $definition->key, $calendar));
            }

            if ($version->actor_mode === ActorMode::OnBehalfOf && $version->trigger_type === TriggerType::Event) {
                foreach (WorkflowsConfig::events() as $class => $mapping) {
                    // A self-describing event names its own user.
                    if (! $mapping->selfDescribing() && $mapping->key === $version->trigger_key && $mapping->user === null) {
                        $findings[] = new DoctorFinding('warn', 'events', sprintf('[%s] runs on behalf of a user, but %s maps no user.', $definition->key, class_basename($class)));
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

        foreach (WorkflowsConfig::events() as $class => $mapping) {
            if (! class_exists($class)) {
                $findings[] = new DoctorFinding('fail', 'events', sprintf('%s does not exist.', class_basename($class)));

                continue;
            }

            $selfDescribing = is_subclass_of($class, WorkflowEvent::class);

            if ($mapping->selfDescribing() && ! $selfDescribing) {
                $findings[] = new DoctorFinding('fail', 'events', sprintf('%s is mapped as self-describing (*) but is not a WorkflowEvent.', class_basename($class)));
            } elseif (! $selfDescribing && $mapping->idempotencyKey === null) {
                $findings[] = new DoctorFinding('warn', 'events', sprintf('%s → %s carries no idempotency key: every event starts an instance.', class_basename($class), $mapping->key));
            }
        }

        return $findings;
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
            ? [new DoctorFinding('fail', 'webhooks', 'The webhook route is enabled without workflows.webhooks.verify_middleware: it stays unregistered.')]
            : [new DoctorFinding('ok', 'webhooks', implode(', ', WorkflowsConfig::webhookVerifyMiddleware()))];
    }
}
