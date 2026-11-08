<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\DataTransferObjects\EmitResult;
use RoundlyConsulting\Workflows\DataTransferObjects\EventMapping;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Support\EventMappings;
use RoundlyConsulting\Workflows\Support\Values;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Turns a mapped Laravel event into `Workflows::emit()` — after the host's transaction commits,
 * so a rolled-back request starts nothing and no definition lock is held during the host's
 * transaction.
 */
final readonly class EmitMappedEvent implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private WorkflowsManager $workflows,
        private EventMappings $mappings,
    ) {}

    public function handle(object $event): void
    {
        if ($event instanceof WorkflowEvent) {
            $this->report($this->workflows->emit($event->workflowKey(), $event->workflowSubject(), $event->workflowPayload(), $event->workflowIdempotencyKey(), $event->workflowUser()));

            return;
        }

        $mapping = $this->mappings->find($event::class);

        if ($mapping === null || $mapping->selfDescribing()) {
            return;
        }

        $subject = $mapping->subject === null ? null : ($event->{$mapping->subject} ?? null);
        $user = $mapping->user === null ? null : ($event->{$mapping->user} ?? null);

        $this->report($this->workflows->emit(
            $mapping->key,
            $subject instanceof Model ? $subject : null,
            $this->payload($event, $mapping),
            $this->idempotencyKey($event, $mapping),
            $user instanceof Model ? $user : null,
        ));
    }

    /**
     * Nobody reads this emit's result, so a definition that refused to start goes to the
     * exception handler (the log) instead of failing the host's request.
     */
    private function report(EmitResult $result): void
    {
        foreach ($result->failed as $failure) {
            report($failure->exception);
        }
    }

    /**
     * The listed public properties, or what the named method returns; models become `{id, type}`.
     *
     * @return array<string, mixed>
     */
    private function payload(object $event, EventMapping $mapping): array
    {
        if (is_string($mapping->payload)) {
            $values = method_exists($event, $mapping->payload) ? $event->{$mapping->payload}() : null;

            return is_array($values) ? GraphParser::stringKeyed(array_map(Values::plain(...), $values)) : [];
        }

        $payload = [];

        foreach ($mapping->payload ?? [] as $property) {
            $payload[$property] = Values::plain($event->{$property} ?? null);
        }

        return $payload;
    }

    private function idempotencyKey(object $event, EventMapping $mapping): ?string
    {
        $value = $mapping->idempotencyKey === null ? null : ($event->{$mapping->idempotencyKey} ?? null);

        if ($value instanceof Model) {
            $value = $value->getKey();
        }

        return is_int($value) || (is_string($value) && $value !== '') ? $mapping->key.':'.$value : null;
    }
}
