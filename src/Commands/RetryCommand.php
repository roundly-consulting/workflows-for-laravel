<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Retries a failed instance (by id or ulid) from the step that failed.
 */
final class RetryCommand extends Command
{
    protected $signature = 'workflows:retry {instance}';

    protected $description = 'Retry a failed workflow instance';

    public function handle(WorkflowsManager $workflows): int
    {
        $instance = $workflows->instances()->find((string) $this->argument('instance'));

        if ($instance === null) {
            $this->error('Instance not found.');

            return self::FAILURE;
        }

        try {
            $workflows->instances()->retry($instance);
        } catch (WorkflowsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Retried.');

        return self::SUCCESS;
    }
}
