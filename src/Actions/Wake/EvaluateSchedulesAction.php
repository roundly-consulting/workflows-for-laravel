<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Wake;

use Carbon\CarbonImmutable;
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
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Schedule triggers: for each active scheduled definition, under its row lock, the due local
 * slots since the cursor (bounded to `schedule.catch_up`) are coalesced into one start of the
 * latest one, keyed by the local wall-clock slot plus the IANA zone name — a repeated DST hour
 * runs once, a skipped one not at all, and a version published mid-minute never double-starts.
 *
 * @internal
 */
final readonly class EvaluateSchedulesAction
{
    public function __construct(
        private StartInstanceAction $start,
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
            if ($this->evaluate($definition, $now)) {
                $started++;
            }
        }

        return $started;
    }

    private function evaluate(WorkflowDefinition $definition, CarbonImmutable $now): bool
    {
        $definitionModel = WorkflowModels::definition();
        $connection = (new $definitionModel)->getConnection();

        return $connection->transaction(function () use ($definition, $definitionModel, $now): bool {
            $locked = $definitionModel::query()->lockForUpdate()->find(Keys::of($definition));
            $versionModel = WorkflowModels::version();
            $version = $locked instanceof WorkflowDefinition ? $versionModel::query()->lockForUpdate()->find($locked->current_version_id) : null;

            if (! $locked instanceof WorkflowDefinition || ! $version instanceof WorkflowVersion) {
                return false;
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
                return false;
            }

            $slot = $cron->previousBefore($now->setTimezone($timezone), $since->setTimezone($timezone));

            if ($slot === null) {
                return false;
            }

            try {
                $result = $this->start->execute(new StartData(
                    key: $locked->key,
                    owner: Definitions::owner($locked),
                    idempotencyKey: 'schedule:'.Keys::string($locked).':'.$slot->format('Y-m-d\TH:i').' '.$timezone,
                    trigger: TriggerType::Schedule,
                    payload: ['slot' => $slot->format('Y-m-d\TH:i'), 'timezone' => $timezone, 'at' => Clock::utc($slot)->toIso8601String()],
                ));
            } catch (WorkflowsException) {
                return false;
            }

            return $result->created;
        });
    }
}
