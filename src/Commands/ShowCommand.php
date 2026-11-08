<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use RoundlyConsulting\Workflows\Http\Resources\InstanceResource;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * One instance, read-only: its status, definition and version, owner and subject (as
 * `basename#id`, never a class name), the path, the step log, the pending waits and the next
 * escalation. `--json` prints the instance detail resource.
 */
final class ShowCommand extends Command
{
    protected $signature = 'workflows:show {instance : The instance id or ULID} {--json : Print the instance detail as JSON}';

    protected $description = 'Show a workflow instance with its step log, waits and escalation';

    public function handle(WorkflowsManager $workflows): int
    {
        $instance = $workflows->instances()->find((string) $this->argument('instance'));

        if ($instance === null) {
            $this->error('Instance not found.');

            return self::FAILURE;
        }

        $instance = $workflows->instances()->detail($instance);

        if ($this->option('json') === true) {
            $this->line((string) json_encode((new InstanceResource($instance))->resolve(Request::create('/')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(['Instance', 'Definition', 'Version', 'Status', 'Owner', 'Subject', 'Failure'], [[
            $instance->ulid,
            $instance->definition->key ?? '',
            (string) ($instance->version->number ?? ''),
            $instance->status->value,
            self::reference($instance->owner_type, $instance->owner_id),
            self::reference($instance->subject_type, $instance->subject_id),
            $instance->failure_code ?? '',
        ]]);

        $this->line('Path: '.implode(' → ', $instance->path()));

        $this->table(['Node', 'Visit', 'Attempt', 'Status', 'Outcome', 'Error', 'Started', 'Finished'], $instance->stepRuns->map(static fn (StepRun $run): array => [
            $run->node_id,
            (string) $run->visit,
            (string) $run->attempt,
            $run->status->value,
            $run->outcome ?? '',
            $run->error_code ?? '',
            $run->started_at?->toDateTimeString() ?? '',
            $run->finished_at?->toDateTimeString() ?? '',
        ])->all());

        if ($instance->waits->isNotEmpty()) {
            $this->table(['Waiting on', 'Event', 'Due'], $instance->waits->map(static fn (WorkflowWait $wait): array => [
                $wait->kind->value,
                $wait->event_key ?? '',
                $wait->due_at?->toDateTimeString() ?? '',
            ])->all());
        }

        $next = $instance->escalations->first();

        if ($next instanceof WorkflowEscalation) {
            $this->line(sprintf('Next escalation: level %d at %s', $next->level, $next->due_at->toDateTimeString()));
        }

        return self::SUCCESS;
    }

    /**
     * `basename#id` through the morph map, never the stored class name.
     */
    private static function reference(?string $type, int|string|null $id): string
    {
        if ($type === null || $id === null) {
            return '';
        }

        return class_basename(Relation::getMorphedModel($type) ?? $type).'#'.$id;
    }
}
