<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\TemplatePayload;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Validates a definition's current version (or its draft), or a definition file (`--file`, the
 * template envelope — no definition needed): prints the report, exits 1 on errors.
 */
final class ValidateCommand extends Command
{
    protected $signature = 'workflows:validate {key? : The definition key} {--owner-type=} {--owner-id=} {--draft} {--file= : A definition file to validate instead}';

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

        $file = $this->option('file');

        if (! is_string($file) && ! is_string($this->argument('key'))) {
            $this->error('Name a definition key or pass --file.');

            return self::FAILURE;
        }

        try {
            if (is_string($file)) {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                $template = TemplatePayload::fromArray(is_array($decoded) ? GraphParser::stringKeyed($decoded) : []);
                $report = $workflows->versions()->validate($template->graph, $template->subjectType, $owner);

                return $this->print($report);
            }

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

        return $this->print($report);
    }

    private function print(ValidationReport $report): int
    {
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
