<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Every minute: wake due timers, retries, timeouts, condition polls, escalations and schedules.
 * `Schedule::command('workflows:wake')->everyMinute()->withoutOverlapping(10)->onOneServer();`
 */
final class WakeCommand extends Command
{
    protected $signature = 'workflows:wake';

    protected $description = 'Wake due workflow timers, retries, escalations and schedules';

    public function handle(WorkflowsManager $workflows): int
    {
        $report = $workflows->wake();

        $this->table(['What', 'Count'], array_map(
            static fn (string $what, int $count): array => [$what, (string) $count],
            array_keys($report->toArray()),
            $report->toArray(),
        ));

        return self::SUCCESS;
    }
}
