<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\SyncedDefinition;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Creates, drafts and (with `--publish`) publishes definitions from `*.json` files (default
 * `workflows.sync.path`); unchanged files do nothing and nothing is retired. Exits 1 when a file
 * is invalid or failed.
 */
final class SyncCommand extends Command
{
    protected $signature = 'workflows:sync
        {path? : A directory of definition files, or one file (default: workflows.sync.path)}
        {--owner-type= : The owner morph alias (or class); app-wide when omitted}
        {--owner-id= : The owner id}
        {--publish : Publish every created or changed definition that is valid}
        {--dry : Report what would change without writing}';

    protected $description = 'Sync workflow definitions from JSON files';

    public function handle(WorkflowsManager $workflows): int
    {
        $type = $this->option('owner-type');
        $id = $this->option('owner-id');
        $owner = is_string($type) && is_string($id) ? Runtime::load($type, $id) : null;

        if (is_string($type) && ! $owner instanceof Model) {
            $this->error('Owner not found.');

            return self::FAILURE;
        }

        $path = $this->argument('path');
        $report = $workflows->definitions()->sync(is_string($path) ? $path : null, $owner, $this->option('publish') === true, $this->option('dry') === true);

        if ($report->definitions === []) {
            $this->info('No definition files.');
        } else {
            $this->table(['File', 'Key', 'Result', 'Version', 'Note'], array_map(static fn (SyncedDefinition $definition): array => [
                $definition->file,
                $definition->key ?? '',
                $definition->status->value,
                $definition->version === null ? '' : (string) $definition->version,
                $definition->draftOverwritten ? 'replaced a draft edited elsewhere' : ($definition->message ?? ''),
            ], $report->definitions));
        }

        if ($report->untracked !== []) {
            $this->line('Untracked (no file, left as they are): '.implode(', ', $report->untracked));
        }

        if ($report->dryRun) {
            $this->comment('Dry run: nothing was written.');
        }

        return $report->failed() ? self::FAILURE : self::SUCCESS;
    }
}
