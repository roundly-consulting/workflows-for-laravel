<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Workflows\Enums\DoctorLevel;
use RoundlyConsulting\Workflows\Support\Doctor;

/**
 * Checks the installation and the running system (see Doctor). Exits 1 on a `fail` finding, and
 * on a `warn` too under `--strict`; `--json` prints the findings for CI and health checks.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'workflows:doctor
        {--json : Print the findings as JSON}
        {--strict : Exit non-zero on warnings too}';

    protected $description = 'Check the workflows installation';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run();

        if ($this->option('json') === true) {
            $this->line((string) json_encode(array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Level', 'Check', 'Message'], array_map(
                static fn (DoctorFinding $finding): array => [strtoupper($finding->level->value), $finding->check, $finding->message],
                $findings,
            ));
        }

        $failing = $this->option('strict') === true ? [DoctorLevel::Fail, DoctorLevel::Warn] : [DoctorLevel::Fail];

        foreach ($findings as $finding) {
            if (in_array($finding->level, $failing, true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
