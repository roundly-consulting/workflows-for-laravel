<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\SyncStatus;

/**
 * One definition file a sync read: its key, what happened (or, in a dry run, would happen),
 * whether a draft edited elsewhere was replaced, the version published, and why it failed.
 */
final readonly class SyncedDefinition
{
    public function __construct(
        public string $file,
        public ?string $key,
        public SyncStatus $status,
        public bool $draftOverwritten = false,
        public ?int $version = null,
        public ?string $message = null,
        public ?ValidationReport $report = null,
    ) {}
}
