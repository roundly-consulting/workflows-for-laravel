<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * One `workflows:doctor` finding: `ok`, `warn` or `fail`.
 */
final readonly class DoctorFinding
{
    public function __construct(
        public string $level,
        public string $check,
        public string $message,
    ) {}
}
