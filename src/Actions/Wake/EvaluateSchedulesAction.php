<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Wake;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Instances\StartInstanceAction;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\VersionStatus;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\Builtin\TriggerStep;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\ValueScope;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Schedule triggers: for each active scheduled definition, under its row lock, the due local
 * slots since the cursor (bounded to `schedule.catch_up`) are coalesced into one start of the
 * latest one, keyed by the local wall-clock slot plus the IANA zone name — a repeated DST hour
 * runs once, a skipped one not at all, and a version published mid-minute never double-starts.
 * A `for_each` schedule starts one instance per matching subject of the definition's subject
 * type per slot (keyed by the slot and the subject), owner-scoped and bounded by `wake.batch`.
 *
 * @internal
 */
final readonly class EvaluateSchedulesAction
{
    public function __construct(
        private StartInstanceAction $start,
        private SubjectRegistry $subjects,
        private Owners $owners,
    ) {}

    public function execute(CarbonImmutable $now): int
    {
        $definitionModel = WorkflowModels::definition();
        $versionModel = WorkflowModels::version();

        $definitions = $definitionModel::query()
            ->where('status', DefinitionStatus::Active->value)
            ->whereIn('current_version_id', $versionModel::query()
                ->where('status', VersionStatus::Published->value)
                ->where('trigger_type', TriggerType::Schedule->value)
                ->select('id'))
            ->get();

        $started = 0;

        foreach ($definitions as $definition) {
            $started += $this->evaluate($definition, $now);
        }

        return $started;
    }

    /**
     * How many instances the definition's due slot started (one, or one per subject).
     */
    private function evaluate(WorkflowDefinition $definition, CarbonImmutable $now): int
    {
        $definitionModel = WorkflowModels::definition();
        $connection = (new $definitionModel)->getConnection();

        return $connection->transaction(function () use ($definition, $definitionModel, $now): int {
            $locked = $definitionModel::query()->lockForUpdate()->find(Keys::of($definition));
            $versionModel = WorkflowModels::version();
            $version = $locked instanceof WorkflowDefinition ? $versionModel::query()->lockForUpdate()->find($locked->current_version_id) : null;

            if (! $locked instanceof WorkflowDefinition || ! $version instanceof WorkflowVersion) {
                return 0;
            }

            $cron = TriggerStep::cronOf($version->trigger);
            $timezone = is_string($version->trigger['timezone'] ?? null) && WorkflowsConfig::isTimezone($version->trigger['timezone'])
                ? $version->trigger['timezone']
                : Runtime::timezone($version);

            $cursor = $version->schedule_cursor_at ?? $version->published_at ?? $now;
            $floor = $now->sub(WorkflowsConfig::scheduleCatchUp());
            $since = $cursor->greaterThan($floor) ? $cursor : $floor;

            $version->schedule_cursor_at = $now;
            $version->save();

            if ($cron === null) {
                return 0;
            }

            $slot = $cron->previousBefore($now->setTimezone($timezone), $since->setTimezone($timezone));

            if ($slot === null) {
                return 0;
            }

            $key = 'schedule:'.Keys::string($locked).':'.$slot->format('Y-m-d\TH:i').' '.$timezone;
            $payload = ['slot' => $slot->format('Y-m-d\TH:i'), 'timezone' => $timezone, 'at' => Clock::utc($slot)->toIso8601String()];
            $owner = Definitions::owner($locked);

            if (! is_array($version->trigger['for_each'] ?? null)) {
                return $this->start($locked->key, $owner, null, $key, $payload);
            }

            $started = 0;

            foreach ($this->subjects($locked, $version, $owner, $now, $timezone) as $subject) {
                $started += $this->start($locked->key, $owner, $subject, $key.':'.Keys::string($subject), $payload);
            }

            return $started;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function start(string $key, ?Model $owner, ?Model $subject, string $idempotencyKey, array $payload): int
    {
        try {
            $result = $this->start->execute(new StartData(
                key: $key,
                subject: $subject,
                owner: $owner,
                idempotencyKey: $idempotencyKey,
                trigger: TriggerType::Schedule,
                payload: $payload,
            ));
        } catch (WorkflowsException) {
            return 0;
        }

        return $result->created ? 1 : 0;
    }

    /**
     * The subjects a `for_each` schedule runs for: of the definition's subject type, of its owner
     * (for an owned definition), matching `where`, at most `limit` (and `wake.batch`) per slot.
     *
     * @return list<Model>
     */
    private function subjects(WorkflowDefinition $definition, WorkflowVersion $version, ?Model $owner, CarbonImmutable $now, string $timezone): array
    {
        $type = $definition->subject_key === null ? null : $this->subjects->find($definition->subject_key);
        $forEach = is_array($version->trigger['for_each'] ?? null) ? $version->trigger['for_each'] : [];
        $where = is_array($forEach['where'] ?? null) ? $forEach['where'] : null;
        $limit = min(is_int($forEach['limit'] ?? null) ? $forEach['limit'] : PHP_INT_MAX, WorkflowsConfig::wakeBatch());

        if ($type === null) {
            return [];
        }

        $class = $type->model;
        $matches = [];

        foreach ($class::query()->orderBy((new $class)->getKeyName())->lazy() as $subject) {
            if ($owner !== null && ! $this->owners->matches($owner, $subject)) {
                continue;
            }

            $scope = new ValueScope($subject, $type, $owner, $owner === null ? null : $this->subjects->forModel($owner), [], [], static fn (string $node): ?array => null, $now, $timezone);

            if ($where !== null && ! Evaluator::evaluate($where, $scope)) {
                continue;
            }

            $matches[] = $subject;

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }
}
