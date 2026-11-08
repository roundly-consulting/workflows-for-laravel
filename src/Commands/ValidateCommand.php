<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Validates a definition's current version (or its draft): prints the report, exits 1 on errors.
 */
final class ValidateCommand extends Command
{
    protected $signature = 'workflows:validate {key} {--owner-type=} {--owner-id=} {--draft}';

    protected $description = 'Validate a workflow definition';

    public function handle(WorkflowsManager $workflows): int
    {
        $type = $this->option('owner-type');
        $id = $this->option('owner-id');
        $owner = is_string($type) && is_string($id) ? Runtime::load($type, $id) : null;

        if (is_string($type) && ! $owner instanceof Model) {
            $this->error('Owner not found.');

            return self::FAILURE;
        }

        try {
            $handle = $workflows->definition((string) $this->argument('key'), $owner);
            $definition = $handle->model();
            $report = $this->option('draft') === true
                ? $handle->validate()
                : ($definition->currentVersion instanceof WorkflowVersion
                    ? $workflows->versions()->validate($definition->currentVersion->graph, $definition->subject_key, $owner)
                    : $handle->validate());
        } catch (WorkflowsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report->errors === []) {
            $this->info('Valid.');

            return self::SUCCESS;
        }

        $this->table(['Severity', 'Code', 'Node', 'Path', 'Message'], array_map(static fn (ValidationError $error): array => [
            $error->severity->value,
            $error->code->value,
            $error->node ?? '',
            $error->path ?? '',
            $error->message,
        ], $report->errors));

        return $report->valid() ? self::SUCCESS : self::FAILURE;
    }
}
