<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Installed by `Workflows::fake()`. Record-only: every mutating call — starts, emits, signals,
 * creates, edits, publishes, retirements, webhook rotations, retries, cancels, imports, wakes,
 * prunes, and calls through the HasWorkflows trait — is recorded and NOT run; it returns an
 * unsaved stand-in (`exists === false`; retry/cancel hand back the instance they were given, a
 * start is `skipped: 'faked'`). Reads (definitions, the catalogue, subjects, validation, diffs,
 * overlays, dry runs, graph building) pass through to the real store. `passthrough()` records and
 * runs. An emit's fan-out is not run, so assert the emit, not the starts it would cause.
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
     * @param  Closure(): TResult  $standIn
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(WorkflowOperation $operation, string $action, Closure $execute, Closure $standIn, array $context = []): mixed
    {
        if ($operation === WorkflowOperation::DryRun) {
            return parent::perform($operation, $action, $execute, $standIn, $context);
        }

        $this->recorded[] = new RecordedOperation($operation, $context);

        return $this->passthrough ? parent::perform($operation, $action, $execute, $standIn, $context) : $standIn();
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

    public function assertNothingEmitted(): void
    {
        $emits = $this->recorded(WorkflowOperation::Emit);

        PHPUnit::assertEmpty($emits, sprintf('Expected nothing to be emitted, but %d emit(s) were recorded.', count($emits)));
    }

    public function assertCreated(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::CreateDefinition, $key), sprintf('Expected [%s] to be created, but it was not.', $key));
    }

    public function assertNotCreated(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::CreateDefinition, $key), sprintf('Expected [%s] not to be created, but it was.', $key));
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $where  receives the saved graph
     */
    public function assertEdited(string $key, ?Closure $where = null): void
    {
        $edits = array_filter(
            $this->forKey(WorkflowOperation::Edit, $key),
            static function (RecordedOperation $recorded) use ($where): bool {
                $graph = $recorded->context['graph'] ?? null;

                return $where === null || (is_array($graph) && $where(GraphParser::stringKeyed($graph)));
            },
        );

        PHPUnit::assertNotEmpty($edits, sprintf('Expected [%s] to be edited, but no edit matched.', $key));
    }

    public function assertNotEdited(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::Edit, $key), sprintf('Expected [%s] not to be edited, but it was.', $key));
    }

    public function assertPublished(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::Publish, $key), sprintf('Expected [%s] to be published, but it was not.', $key));
    }

    public function assertNotPublished(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::Publish, $key), sprintf('Expected [%s] not to be published, but it was.', $key));
    }

    public function assertRetired(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::Retire, $key), sprintf('Expected [%s] to be retired, but it was not.', $key));
    }

    public function assertNotRetired(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::Retire, $key), sprintf('Expected [%s] not to be retired, but it was.', $key));
    }

    public function assertActivated(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::Activate, $key), sprintf('Expected [%s] to be activated, but it was not.', $key));
    }

    public function assertNotActivated(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::Activate, $key), sprintf('Expected [%s] not to be activated, but it was.', $key));
    }

    public function assertRestored(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::RestoreDefinition, $key), sprintf('Expected [%s] to be restored, but it was not.', $key));
    }

    public function assertNotRestored(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::RestoreDefinition, $key), sprintf('Expected [%s] not to be restored, but it was.', $key));
    }

    public function assertDraftDiscarded(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::DiscardDraft, $key), sprintf('Expected the draft of [%s] to be discarded, but it was not.', $key));
    }

    public function assertDraftNotDiscarded(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::DiscardDraft, $key), sprintf('Expected the draft of [%s] not to be discarded, but it was.', $key));
    }

    public function assertFlagResolved(string $key, ?string $node = null, ?string $path = null): void
    {
        $resolved = array_filter(
            $this->forKey(WorkflowOperation::ResolveFlag, $key),
            static fn (RecordedOperation $recorded): bool => ($node === null || ($recorded->context['node'] ?? null) === $node)
                && ($path === null || ($recorded->context['path'] ?? null) === $path),
        );

        PHPUnit::assertNotEmpty($resolved, sprintf('Expected a review flag of [%s] to be resolved, but none matched.', $key));
    }

    public function assertNoFlagResolved(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::ResolveFlag, $key), sprintf('Expected no review flag of [%s] to be resolved, but one was.', $key));
    }

    public function assertWebhookRotated(string $key): void
    {
        PHPUnit::assertNotEmpty($this->forKey(WorkflowOperation::RotateWebhook, $key), sprintf('Expected the webhook of [%s] to be rotated, but it was not.', $key));
    }

    public function assertWebhookNotRotated(string $key): void
    {
        PHPUnit::assertEmpty($this->forKey(WorkflowOperation::RotateWebhook, $key), sprintf('Expected the webhook of [%s] not to be rotated, but it was.', $key));
    }

    /**
     * @param  string|null  $key  the imported definition key (null = any import)
     */
    public function assertImported(?string $key = null): void
    {
        $imports = $key === null ? $this->recorded(WorkflowOperation::Import) : $this->forKey(WorkflowOperation::Import, $key);

        PHPUnit::assertNotEmpty($imports, $key === null ? 'Expected a template to be imported, but none was.' : sprintf('Expected [%s] to be imported, but it was not.', $key));
    }

    public function assertNothingImported(): void
    {
        $imports = $this->recorded(WorkflowOperation::Import);

        PHPUnit::assertEmpty($imports, sprintf('Expected nothing to be imported, but %d import(s) were recorded.', count($imports)));
    }

    public function assertWebhookReceived(string $hookId): void
    {
        PHPUnit::assertNotEmpty($this->matching(WorkflowOperation::Webhook, 'hook', $hookId), sprintf('Expected webhook [%s] to be received, but it was not.', $hookId));
    }

    public function assertWebhookNotReceived(string $hookId): void
    {
        PHPUnit::assertEmpty($this->matching(WorkflowOperation::Webhook, 'hook', $hookId), sprintf('Expected webhook [%s] not to be received, but it was.', $hookId));
    }

    /**
     * A webhooks-for-laravel delivery on that inbound endpoint (`triggers()->delivery()`).
     */
    public function assertWebhookDeliveryReceived(int|string $endpoint): void
    {
        PHPUnit::assertNotEmpty($this->matching(WorkflowOperation::Webhook, 'endpoint', (string) $endpoint), sprintf('Expected a webhook delivery on endpoint [%s], but none was received.', $endpoint));
    }

    public function assertWebhookDeliveryNotReceived(int|string $endpoint): void
    {
        PHPUnit::assertEmpty($this->matching(WorkflowOperation::Webhook, 'endpoint', (string) $endpoint), sprintf('Expected no webhook delivery on endpoint [%s], but one was received.', $endpoint));
    }

    public function assertRetried(WorkflowInstance $instance): void
    {
        PHPUnit::assertTrue($this->concerns(WorkflowOperation::Retry, $instance), sprintf('Expected instance [%s] to be retried, but it was not.', $instance->ulid));
    }

    public function assertNotRetried(WorkflowInstance $instance): void
    {
        PHPUnit::assertFalse($this->concerns(WorkflowOperation::Retry, $instance), sprintf('Expected instance [%s] not to be retried, but it was.', $instance->ulid));
    }

    public function assertCancelled(WorkflowInstance $instance): void
    {
        PHPUnit::assertTrue($this->concerns(WorkflowOperation::Cancel, $instance), sprintf('Expected instance [%s] to be cancelled, but it was not.', $instance->ulid));
    }

    public function assertNotCancelled(WorkflowInstance $instance): void
    {
        PHPUnit::assertFalse($this->concerns(WorkflowOperation::Cancel, $instance), sprintf('Expected instance [%s] not to be cancelled, but it was.', $instance->ulid));
    }

    /**
     * @param  int|null  $times  exactly this many wake passes (null = at least one)
     */
    public function assertWoken(?int $times = null): void
    {
        $wakes = $this->recorded(WorkflowOperation::Wake);

        if ($times !== null) {
            PHPUnit::assertCount($times, $wakes, sprintf('Expected workflows to be woken %d time(s), but they were woken %d time(s).', $times, count($wakes)));

            return;
        }

        PHPUnit::assertNotEmpty($wakes, 'Expected workflows to be woken, but wake() was not called.');
    }

    public function assertNotWoken(): void
    {
        $wakes = $this->recorded(WorkflowOperation::Wake);

        PHPUnit::assertEmpty($wakes, sprintf('Expected workflows not to be woken, but wake() was called %d time(s).', count($wakes)));
    }

    public function assertPruned(): void
    {
        PHPUnit::assertNotEmpty($this->recorded(WorkflowOperation::Prune), 'Expected workflows to be pruned, but prune() was not called.');
    }

    public function assertNotPruned(): void
    {
        PHPUnit::assertEmpty($this->recorded(WorkflowOperation::Prune), 'Expected workflows not to be pruned, but prune() was called.');
    }

    /**
     * @param  Model|null  $subject  this subject (null = any subject)
     */
    public function assertTouched(?Model $subject = null): void
    {
        PHPUnit::assertNotEmpty($this->touches($subject), $subject === null ? 'Expected a subject to be touched, but none was.' : sprintf('Expected %s#%s to be touched, but it was not.', class_basename($subject), (string) Keys::of($subject)));
    }

    public function assertNotTouched(?Model $subject = null): void
    {
        PHPUnit::assertEmpty($this->touches($subject), $subject === null ? 'Expected no subject to be touched, but one was.' : sprintf('Expected %s#%s not to be touched, but it was.', class_basename($subject), (string) Keys::of($subject)));
    }

    /**
     * @param  string|null  $path  this path (null = any sync)
     */
    public function assertSynced(?string $path = null, ?bool $publish = null): void
    {
        $syncs = array_filter(
            $this->recorded(WorkflowOperation::Sync),
            static fn (RecordedOperation $recorded): bool => ($path === null || ($recorded->context['path'] ?? null) === $path)
                && ($publish === null || ($recorded->context['publish'] ?? null) === $publish),
        );

        PHPUnit::assertNotEmpty($syncs, $path === null ? 'Expected definitions to be synced, but sync() was not called.' : sprintf('Expected [%s] to be synced, but it was not.', $path));
    }

    public function assertNotSynced(): void
    {
        $syncs = $this->recorded(WorkflowOperation::Sync);

        PHPUnit::assertEmpty($syncs, sprintf('Expected no sync, but sync() was called %d time(s).', count($syncs)));
    }

    /**
     * Nothing at all was recorded (dry runs never are).
     */
    public function assertNothingRecorded(): void
    {
        $names = array_map(static fn (RecordedOperation $recorded): string => $recorded->operation->value, $this->recorded);

        PHPUnit::assertEmpty($names, sprintf('Expected no workflow operation, but these were recorded: %s.', implode(', ', $names)));
    }

    /**
     * @return list<RecordedOperation>
     */
    private function forKey(WorkflowOperation $operation, string $key): array
    {
        return $this->matching($operation, 'key', $key);
    }

    /**
     * @return list<RecordedOperation>
     */
    private function matching(WorkflowOperation $operation, string $field, string $value): array
    {
        return array_values(array_filter(
            $this->recorded($operation),
            static fn (RecordedOperation $recorded): bool => ($recorded->context[$field] ?? null) === $value,
        ));
    }

    /**
     * @return list<RecordedOperation>
     */
    private function touches(?Model $subject): array
    {
        return array_values(array_filter(
            $this->recorded(WorkflowOperation::Touch),
            static function (RecordedOperation $recorded) use ($subject): bool {
                $touched = $recorded->context['subject'] ?? null;

                return $subject === null || ($touched instanceof Model && $touched->is($subject));
            },
        ));
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

    private function concerns(WorkflowOperation $operation, WorkflowInstance $instance): bool
    {
        foreach ($this->recorded($operation) as $recorded) {
            $value = $recorded->context['instance'] ?? null;

            if ($value instanceof WorkflowInstance && $value->is($instance)) {
                return true;
            }
        }

        return false;
    }
}
