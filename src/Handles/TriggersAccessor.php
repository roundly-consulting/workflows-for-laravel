<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use RoundlyConsulting\Workflows\Actions\Instances\ReceiveWebhookAction;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\DataTransferObjects\EventMapping;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Support\EventMappings;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Trigger entry points beyond manual starts and emits: the inbound webhook start call (the same
 * as the opt-in route, without HTTP), and Laravel events mapped to emits — in config or in code.
 */
final readonly class TriggersAccessor
{
    public function __construct(
        private WorkflowsManager $manager,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function webhook(string $hookId, array $payload, array $headers = []): StartResult
    {
        return $this->manager->perform(
            WorkflowOperation::Webhook,
            ReceiveWebhookAction::class,
            static fn (ReceiveWebhookAction $action): StartResult => $action->execute($hookId, $payload, $headers),
            static fn (): StartResult => new StartResult(null, false, skipped: 'faked'),
            ['hook' => $hookId, 'payload' => $payload],
        );
    }

    /**
     * Map a Laravel event to a trigger key from code (a service provider's `boot()`): the event
     * properties holding the subject and the user, the payload — a list of public properties or
     * one method returning an array — and the property that makes the emit idempotent
     * (`"{key}:{value}"`). Emits after the host's transaction commits. Mapping a class again
     * replaces its mapping.
     *
     * @param  list<string>|string|null  $payload
     */
    public function map(string $event, string $key, ?string $subject = null, ?string $user = null, array|string|null $payload = null, ?string $idempotencyKey = null): void
    {
        if ($key === '' || $key === EventMapping::SELF_DESCRIBING) {
            throw InvalidWorkflowsConfigurationException::invalidMap('workflows.events.'.class_basename($event), 'a trigger key (use listen() for a WorkflowEvent)');
        }

        $this->mappings()->map($event, new EventMapping(
            $key,
            $subject,
            $user,
            WorkflowsConfig::eventPayload($payload, 'workflows.events.'.class_basename($event).'.payload'),
            $idempotencyKey,
        ));
    }

    /**
     * Listen for a self-describing event (one implementing WorkflowEvent).
     */
    public function listen(string $event): void
    {
        if (! is_subclass_of($event, WorkflowEvent::class)) {
            throw InvalidWorkflowsConfigurationException::notAnImplementation('workflows.events.'.class_basename($event), WorkflowEvent::class, $event);
        }

        $this->mappings()->map($event, new EventMapping(EventMapping::SELF_DESCRIBING));
    }

    /**
     * The mapped Laravel events, config and code: event class basename => trigger key (`*` for
     * a self-describing event). No class names leave through this read either.
     *
     * @return array<string, string>
     */
    public function events(): array
    {
        $events = [];

        foreach ($this->mappings()->all() as $class => $mapping) {
            $events[class_basename($class)] = $mapping->key;
        }

        return $events;
    }

    private function mappings(): EventMappings
    {
        return $this->manager->make(EventMappings::class);
    }
}
