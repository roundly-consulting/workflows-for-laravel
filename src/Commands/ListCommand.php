<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Engine\Runtime;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * The newest instances, read-only: ULID, definition, version, status, current node, started and
 * ended, failure code. Filters: status, definition key, owner (`--owner-type` = its morph alias).
 */
final class ListCommand extends Command
{
    protected $signature = 'workflows:list
        {--status= : running, waiting, completed, failed or cancelled}
        {--definition= : A definition key}
        {--owner-type= : The owner morph alias (or class)}
        {--owner-id= : The owner id}
        {--limit=25 : At most this many}';

    protected $description = 'List workflow instances';

    public function handle(WorkflowsManager $workflows): int
    {
        $status = $this->option('status');
        $status = is_string($status) ? InstanceStatus::tryFrom($status) : null;

        if (is_string($this->option('status')) && $status === null) {
            $this->error('Unknown status: use '.implode(', ', InstanceStatus::values()->all()).'.');

            return self::FAILURE;
        }

        $type = $this->option('owner-type');
        $id = $this->option('owner-id');
        $owner = is_string($type) && is_string($id) ? Runtime::load($type, $id) : null;

        if (is_string($type) && ! $owner instanceof Model) {
            $this->error('Owner not found.');

            return self::FAILURE;
        }

        $definition = $this->option('definition');
        $limit = $this->option('limit');
        $instances = $workflows->instances()->recent($status, is_string($definition) ? $definition : null, $owner, is_numeric($limit) ? (int) $limit : 25);

        if ($instances->isEmpty()) {
            $this->info('No instances.');

            return self::SUCCESS;
        }

        $this->table(['Instance', 'Definition', 'Version', 'Status', 'Node', 'Started', 'Ended', 'Failure'], $instances->map(static fn (WorkflowInstance $instance): array => [
            $instance->ulid,
            $instance->definition->key ?? '',
            (string) ($instance->version->number ?? ''),
            $instance->status->value,
            $instance->current_node_id ?? '',
            $instance->started_at?->toDateTimeString() ?? '',
            $instance->ended_at?->toDateTimeString() ?? '',
            $instance->failure_code ?? '',
        ])->all());

        return self::SUCCESS;
    }
}
