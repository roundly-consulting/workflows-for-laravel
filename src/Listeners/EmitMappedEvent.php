<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
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
    ) {}

    public function handle(object $event): void
    {
        if ($event instanceof WorkflowEvent) {
            $this->workflows->emit($event->workflowKey(), $event->workflowSubject(), $event->workflowPayload(), $event->workflowIdempotencyKey(), $event->workflowUser());

            return;
        }

        $mapping = WorkflowsConfig::events()[$event::class] ?? null;

        if ($mapping === null) {
            return;
        }

        $subject = $mapping['subject'] === null ? null : ($event->{$mapping['subject']} ?? null);
        $user = $mapping['user'] === null ? null : ($event->{$mapping['user']} ?? null);

        $this->workflows->emit(
            $mapping['key'],
            $subject instanceof Model ? $subject : null,
            [],
            null,
            $user instanceof Model ? $user : null,
        );
    }
}
