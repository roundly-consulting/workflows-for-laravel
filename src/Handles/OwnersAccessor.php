<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Owners\ExportOwnerAction;
use RoundlyConsulting\Workflows\Contracts\OwnerState;
use RoundlyConsulting\Workflows\DataTransferObjects\OwnerExport;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Owner-wide operations: everything the package holds for an owner as one export, and whether
 * the configured OwnerState pauses it.
 */
final readonly class OwnersAccessor
{
    public function __construct(
        private WorkflowsManager $manager,
    ) {}

    /**
     * The owner's definitions (every version as a template) and run summaries — read-only, so
     * the fake runs it too.
     */
    public function export(Model $owner): OwnerExport
    {
        return $this->manager->make(ExportOwnerAction::class)->execute($owner);
    }

    /**
     * Whether `workflows.owners.state` pauses the owner (never, when none is configured).
     */
    public function isPaused(Model $owner): bool
    {
        return $this->manager->make(OwnerState::class)->isPaused($owner);
    }
}
