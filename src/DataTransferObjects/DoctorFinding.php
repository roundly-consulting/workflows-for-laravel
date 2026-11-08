<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\DoctorLevel;

/**
 * One `workflows:doctor` finding: its level, the check and what it found.
 */
final readonly class DoctorFinding
{
    public function __construct(
        public DoctorLevel $level,
        public string $check,
        public string $message,
    ) {}

    /**
     * @return array{level: string, check: string, message: string}
     */
    public function toArray(): array
    {
        return ['level' => $this->level->value, 'check' => $this->check, 'message' => $this->message];
    }
}
