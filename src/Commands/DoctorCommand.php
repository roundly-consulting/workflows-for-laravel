<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Workflows\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Workflows\Support\Doctor;

/**
 * Checks the installation: key types, the queue timeout contract, the cache store for
 * `onOneServer()`, the last wake, bridges, adapters active versions need, event mappings,
 * the webhook route's signature middleware, calendars and host step types.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'workflows:doctor';

    protected $description = 'Check the workflows installation';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->run();

        $this->table(['Level', 'Check', 'Message'], array_map(
            static fn (DoctorFinding $finding): array => [strtoupper($finding->level), $finding->check, $finding->message],
            $findings,
        ));

        foreach ($findings as $finding) {
            if ($finding->level === 'fail') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
