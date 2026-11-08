<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Installed by `Workflows::fake()`. Record-only: every mutating call — starts, emits, signals,
 * edits, publishes, retirements, retries, cancels, imports, wakes, prunes, and calls through the
 * HasWorkflows trait — is recorded and NOT run. Reads (definitions, the catalogue, subjects,
 * validation, diffs, overlays, dry runs) pass through to the real store. `passthrough()` records
 * and runs. An emit's fan-out is not run, so assert the emit, not the starts it would cause.
 */
final class WorkflowsFake extends WorkflowsManager
{
    /** @var list<RecordedOperation> */
    private array $recorded = [];

    private bool $passthrough = false;

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function passthrough(bool $passthrough = true): self
    {
        $this->passthrough = $passthrough;

        return $this;
    }

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult|null
     */
    public function perform(WorkflowOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        if ($operation === WorkflowOperation::DryRun) {
            return parent::perform($operation, $action, $execute, $context);
        }

        $this->recorded[] = new RecordedOperation($operation, $context);

        return $this->passthrough ? parent::perform($operation, $action, $execute, $context) : null;
    }

    /**
     * @return list<RecordedOperation>
     */
    public function recorded(?WorkflowOperation $operation = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedOperation $recorded): bool => $operation === null || $recorded->operation === $operation,
        ));
    }

    /**
     * @param  (Closure(StartData): bool)|null  $where
     */
    public function assertStarted(string $key, ?Closure $where = null, ?int $times = null): void
    {
        $starts = $this->starts($key, $where);

        if ($times !== null) {
            PHPUnit::assertCount($times, $starts, sprintf('Expected [%s] to be started %d time(s), but it was started %d time(s).', $key, $times, count($starts)));

            return;
        }

        PHPUnit::assertNotEmpty($starts, sprintf('Expected [%s] to be started, but no start matched.', $key));
    }

    public function assertNotStarted(string $key): void
    {
        $starts = $this->starts($key, null);

        PHPUnit::assertEmpty($starts, sprintf('Expected [%s] not to be started, but it was started %d time(s).', $key, count($starts)));
    }

    public function assertNothingStarted(): void
    {
        $starts = $this->recorded(WorkflowOperation::Start);

        PHPUnit::assertEmpty($starts, sprintf('Expected nothing to be started, but %d start(s) were recorded.', count($starts)));
    }

    /**
     * @param  (Closure(EmitData): bool)|null  $where
     */
    public function assertEmitted(string $event, ?Closure $where = null): void
    {
        PHPUnit::assertNotEmpty($this->emits($event, $where), sprintf('Expected [%s] to be emitted, but no emit matched.', $event));
    }

    public function assertNotEmitted(string $event): void
    {
        $emits = $this->emits($event, null);

        PHPUnit::assertEmpty($emits, sprintf('Expected [%s] not to be emitted, but it was emitted %d time(s).', $event, count($emits)));
    }

    public function assertPublished(string $key): void
    {
        $published = array_filter(
            $this->recorded(WorkflowOperation::Publish),
            static fn (RecordedOperation $recorded): bool => ($recorded->context['key'] ?? null) === $key,
        );

        PHPUnit::assertNotEmpty($published, sprintf('Expected [%s] to be published, but it was not.', $key));
    }

    public function assertRetried(WorkflowInstance $instance): void
    {
        PHPUnit::assertTrue($this->concerns(WorkflowOperation::Retry, $instance), 'Expected the instance to be retried, but it was not.');
    }

    public function assertCancelled(WorkflowInstance $instance): void
    {
        PHPUnit::assertTrue($this->concerns(WorkflowOperation::Cancel, $instance), 'Expected the instance to be cancelled, but it was not.');
    }

    public function assertWoken(): void
    {
        PHPUnit::assertNotEmpty($this->recorded(WorkflowOperation::Wake), 'Expected workflows to be woken, but wake() was not called.');
    }

    public function assertTouched(): void
    {
        PHPUnit::assertNotEmpty($this->recorded(WorkflowOperation::Touch), 'Expected a subject to be touched, but none was.');
    }

    /**
     * @param  (Closure(StartData): bool)|null  $where
     * @return list<StartData>
     */
    private function starts(string $key, ?Closure $where): array
    {
        $starts = [];

        foreach ($this->recorded(WorkflowOperation::Start) as $recorded) {
            $data = $recorded->context['data'] ?? null;

            if ($data instanceof StartData && $data->key === $key && ($where === null || $where($data))) {
                $starts[] = $data;
            }
        }

        return $starts;
    }

    /**
     * @param  (Closure(EmitData): bool)|null  $where
     * @return list<EmitData>
     */
    private function emits(string $event, ?Closure $where): array
    {
        $emits = [];

        foreach ($this->recorded(WorkflowOperation::Emit) as $recorded) {
            $data = $recorded->context['data'] ?? null;

            if ($data instanceof EmitData && $data->event === $event && ($where === null || $where($data))) {
                $emits[] = $data;
            }
        }

        return $emits;
    }

    private function concerns(WorkflowOperation $operation, WorkflowInstance|WorkflowDefinition $model): bool
    {
        foreach ($this->recorded($operation) as $recorded) {
            $value = $recorded->context['instance'] ?? $recorded->context['definition'] ?? null;

            if ($value instanceof WorkflowInstance && $model instanceof WorkflowInstance && $value->is($model)) {
                return true;
            }
        }

        return false;
    }
}
