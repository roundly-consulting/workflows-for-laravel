<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Jobs\RunStepJob;
use RoundlyConsulting\Workflows\Support\Doctor;
use RoundlyConsulting\Workflows\Tests\Readme\ReadmeTestCase;

// Coordinator X-1: Laravel's default queue (`database`, `retry_after` 90 s) is shorter than the step
// job timeout, so a fresh install fails `workflows:doctor`. The README's Installation says what to set,
// and a host that sets it passes.

beforeEach(function (): void {
    config()->set('queue.default', 'database');
    config()->set('workflows.queue.enabled', 'on');
});

/**
 * @return list<string>
 */
function queueTimeoutFindings(): array
{
    $findings = array_filter(app(Doctor::class)->run(), static fn ($finding): bool => $finding->check === 'queue timeout');

    return array_values(array_map(static fn ($finding): string => $finding->level->value, $findings));
}

it('fails doctor on Laravel\'s default retry_after', function (): void {
    config()->set('queue.connections.database.retry_after', 90);

    expect(queueTimeoutFindings())->toBe(['fail']);
    $this->artisan('workflows:doctor')->assertFailed();
});

it('tells the host in the README Installation what retry_after to set, and that value passes doctor', function (): void {
    $retryAfter = ReadmeTestCase::retryAfter();

    expect(ReadmeTestCase::installation())->toContain('retry_after')
        ->and($retryAfter)->toBeGreaterThan((new RunStepJob(1))->timeout);

    config()->set('queue.connections.database.retry_after', $retryAfter);

    expect(queueTimeoutFindings())->toBe([]);
    $this->artisan('workflows:doctor')->assertSuccessful();
});
