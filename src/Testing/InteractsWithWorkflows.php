<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use LogicException;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Helpers for host (and package) tests: drain the package's queued jobs, move the clock and wake,
 * script a step type's result, and assert where an instance stands and which steps ran.
 */
trait InteractsWithWorkflows
{
    /** @var array<string, ScriptedStepType> */
    private array $scriptedSteps = [];

    /**
     * Script what every node of a step type returns: an outcome (a string), a StepResult, or a
     * closure building one from the StepContext. The real engine runs — locks, waits,
     * escalations and the authorization hook included — only the handler is replaced. A type
     * that is not registered (`approval` without approvals installed) needs its `$outcomes`.
     * Restored when the test ends.
     *
     * @param  StepResult|string|(Closure(StepContext): StepResult)  $result
     * @param  list<string>|null  $outcomes
     */
    protected function fakeStep(string $type, StepResult|string|Closure $result, ?array $outcomes = null): void
    {
        $catalogue = app(StepCatalogue::class);
        $current = $catalogue->find($type);
        $original = $current instanceof ScriptedStepType ? $current->original() : $current;

        if ($original === null && $outcomes === null) {
            throw new LogicException(sprintf('The step type [%s] is not registered: pass the outcomes it can end with.', $type));
        }

        $scripted = new ScriptedStepType($type, $result, $original, $outcomes ?? ['next']);
        $restore = $catalogue->swap($type, $scripted);
        $this->scriptedSteps[$type] = $scripted;

        if (method_exists($this, 'beforeApplicationDestroyed')) {
            $this->beforeApplicationDestroyed($restore);
        }
    }

    /**
     * @param  int|null  $times  exactly this many runs (null = at least one)
     * @param  (Closure(StepContext): bool)|null  $where
     */
    protected function assertStepRan(string $type, ?int $times = null, ?Closure $where = null): void
    {
        $runs = array_values(array_filter(
            ($this->scriptedSteps[$type] ?? null)?->runs() ?? [],
            static fn (StepContext $context): bool => $where === null || $where($context),
        ));

        if ($times !== null) {
            Assert::assertCount($times, $runs, sprintf('Expected the step type [%s] to run %d time(s), it ran %d time(s).', $type, $times, count($runs)));

            return;
        }

        Assert::assertNotEmpty($runs, sprintf('Expected the step type [%s] to run, but no run matched.', $type));
    }

    protected function assertStepNotRan(string $type): void
    {
        $runs = ($this->scriptedSteps[$type] ?? null)?->runs() ?? [];

        Assert::assertEmpty($runs, sprintf('Expected the step type [%s] not to run, but it ran %d time(s).', $type, count($runs)));
    }

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
