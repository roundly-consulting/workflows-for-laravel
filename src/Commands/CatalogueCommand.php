<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\DataTransferObjects\CatalogueEntry;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Lists the step catalogue: key, label, side effects, outcomes, package, availability.
 */
final class CatalogueCommand extends Command
{
    protected $signature = 'workflows:catalogue';

    protected $description = 'List the workflow step types';

    public function handle(WorkflowsManager $workflows): int
    {
        $this->table(
            ['Key', 'Label', 'Side effects', 'Outcomes', 'Package', 'Available'],
            array_map(static fn (CatalogueEntry $entry): array => [
                $entry->key,
                $entry->label,
                $entry->sideEffects ? 'yes' : 'no',
                implode(', ', array_map(static fn (array $outcome): string => $outcome['key'], $entry->outcomes)),
                $entry->package ?? '—',
                $entry->available ? 'yes' : 'no',
            ], $workflows->catalogue()->export()->types),
        );

        return self::SUCCESS;
    }
}
