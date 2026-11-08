<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Helpers for host (and package) tests: drain the package's queued jobs, move the clock and wake,
 * and assert where an instance stands.
 */
trait InteractsWithWorkflows
{
    /**
     * Work the current queue connection until it is empty (capped at 1000 jobs).
     */
    protected function runWorkflows(?string $connection = null): void
    {
        $kernel = app(Kernel::class);
        $default = config('queue.default');
        $connection ??= is_string($default) ? $default : 'sync';

        if ($connection === 'sync') {
            return;
        }

        $kernel->call('queue:work', [
            'connection' => $connection,
            '--stop-when-empty' => true,
            '--max-jobs' => 1000,
            '--sleep' => 0,
            '--tries' => 1,
            // A test process is often past the worker's default 128 MB already: the worker
            // would stop after its first job.
            '--memory' => 4096,
            '--quiet' => true,
        ]);
    }

    /**
     * Move the clock forward (`'P2D'`, `'PT1H'`, an interval, or an absolute instant), wake, and
     * drain the queue.
     */
    protected function travelAndWake(CarbonInterval|CarbonImmutable|string $by): void
    {
        $now = CarbonImmutable::now();

        $target = match (true) {
            $by instanceof CarbonImmutable => $by,
            $by instanceof CarbonInterval => $now->add($by),
            default => str_starts_with($by, 'P') ? $now->add(new CarbonInterval($by)) : CarbonImmutable::parse($by),
        };

        Carbon::setTestNow($target);
        CarbonImmutable::setTestNow($target);

        app(WorkflowsManager::class)->wake();
        $this->runWorkflows();
    }

    protected function assertInstanceAt(WorkflowInstance $instance, string $node): void
    {
        Assert::assertSame($node, $instance->refresh()->current_node_id, sprintf('Expected the instance at [%s], it is at [%s].', $node, (string) $instance->current_node_id));
    }

    protected function assertInstanceStatus(WorkflowInstance $instance, InstanceStatus $status): void
    {
        Assert::assertSame($status, $instance->refresh()->status, sprintf('Expected the instance to be %s, it is %s.', $status->value, $instance->status->value));
    }

    /**
     * @param  list<string>  $path
     */
    protected function assertPath(WorkflowInstance $instance, array $path): void
    {
        Assert::assertSame($path, $instance->path());
    }
}
