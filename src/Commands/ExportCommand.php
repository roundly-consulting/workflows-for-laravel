<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Writes a definition's current (or a chosen) version as `<key>.json` in the template envelope —
 * the file `workflows:sync` reads back.
 */
final class ExportCommand extends Command
{
    protected $signature = 'workflows:export
        {key : The definition key}
        {--number= : A version number (default: the current version; --version is taken by the console)}
        {--path= : The directory (default: workflows.sync.path)}
        {--owner-type= : The owner morph alias (or class); app-wide when omitted}
        {--owner-id= : The owner id}';

    protected $description = 'Export a workflow definition to a JSON file';

    public function handle(WorkflowsManager $workflows, Filesystem $files): int
    {
        $type = $this->option('owner-type');
        $id = $this->option('owner-id');
        $owner = is_string($type) && is_string($id) ? Runtime::load($type, $id) : null;

        if (is_string($type) && ! $owner instanceof Model) {
            $this->error('Owner not found.');

            return self::FAILURE;
        }

        $version = $this->option('number');
        $key = (string) $this->argument('key');

        try {
            $template = $workflows->definition($key, $owner)->export(is_numeric($version) ? (int) $version : null);
        } catch (WorkflowsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $path = $this->option('path');
        $directory = is_string($path) && $path !== '' ? $path : WorkflowsConfig::syncPath();
        $file = rtrim($directory, '/').'/'.$template->key.'.json';

        $files->ensureDirectoryExists($directory);
        $files->put($file, json_encode($template->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");

        $this->info('Exported to '.$file.'.');

        return self::SUCCESS;
    }
}
