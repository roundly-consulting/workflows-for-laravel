<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Daily: remove old ended instance trees, expired trigger keys and old dry-run logs.
 */
final class PruneCommand extends Command
{
    protected $signature = 'workflows:prune';

    protected $description = 'Prune old workflow instances, expired idempotency keys and dry-run logs';

    public function handle(WorkflowsManager $workflows): int
    {
        $report = $workflows->prune();

        $this->info(sprintf('Pruned %d instance(s), %d idempotency key(s), %d dry run(s).', $report->instances, $report->idempotencyKeys, $report->dryRuns));

        return self::SUCCESS;
    }
}
