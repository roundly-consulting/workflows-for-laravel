<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Publishes the config and migrations (refused when a workflows table already exists) and prints
 * the scheduler lines and the subject registration snippet.
 */
final class InstallCommand extends Command
{
    protected $signature = 'workflows:install';

    protected $description = 'Publish the workflows config and migrations';

    public function handle(): int
    {
        foreach (['definitions', 'versions', 'instances', 'step_runs', 'waits', 'escalations', 'idempotency_keys', 'dry_runs'] as $table) {
            if (Schema::hasTable(WorkflowsConfig::table($table))) {
                $this->error(sprintf('The table [%s] already exists: the migrations were already run.', WorkflowsConfig::table($table)));

                return self::FAILURE;
            }
        }

        $this->call('vendor:publish', ['--tag' => 'workflows-config']);
        $this->call('vendor:publish', ['--tag' => 'workflows-migrations']);

        $this->line('');
        $this->line('Run the migrations, then add to routes/console.php:');
        $this->line("  Schedule::command('workflows:wake')->everyMinute()->withoutOverlapping(10)->onOneServer();");
        $this->line("  Schedule::command('workflows:prune')->daily();");
        $this->line('');
        $this->line('Register your subject types (in a service provider) and use HasWorkflows on them:');
        $this->line("  Workflows::subjects()->register(Workflows::subject('purchase_request', PurchaseRequest::class)->field('amount', 'decimal', 'Amount'));");

        return self::SUCCESS;
    }
}
