<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Cancels a running or waiting instance (by id or ulid).
 */
final class CancelCommand extends Command
{
    protected $signature = 'workflows:cancel {instance} {--reason=}';

    protected $description = 'Cancel a workflow instance';

    public function handle(WorkflowsManager $workflows): int
    {
        $instance = $workflows->instances()->find((string) $this->argument('instance'));

        if ($instance === null) {
            $this->error('Instance not found.');

            return self::FAILURE;
        }

        $reason = $this->option('reason');

        try {
            $workflows->instances()->cancel($instance, is_string($reason) ? $reason : null);
        } catch (WorkflowsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Cancelled.');

        return self::SUCCESS;
    }
}
