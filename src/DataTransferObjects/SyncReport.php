<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\SyncStatus;

/**
 * What a sync did, file by file, and the definitions of the owner no file describes (left as
 * they are — a sync never retires or deletes).
 */
final readonly class SyncReport
{
    /**
     * @param  list<SyncedDefinition>  $definitions
     * @param  list<string>  $untracked  definition keys with no file
     */
    public function __construct(
        public array $definitions = [],
        public array $untracked = [],
        public bool $dryRun = false,
    ) {}

    /**
     * @return list<SyncedDefinition>
     */
    public function with(SyncStatus $status): array
    {
        return array_values(array_filter($this->definitions, static fn (SyncedDefinition $definition): bool => $definition->status === $status));
    }

    public function failed(): bool
    {
        return $this->with(SyncStatus::Invalid) !== [] || $this->with(SyncStatus::Failed) !== [];
    }
}
