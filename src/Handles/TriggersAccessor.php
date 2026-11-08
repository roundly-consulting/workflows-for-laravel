<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Handles;

use RoundlyConsulting\Workflows\Actions\Instances\ReceiveWebhookAction;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\Enums\WorkflowOperation;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * Trigger entry points beyond manual starts and emits: the inbound webhook start call (the same
 * as the opt-in route, without HTTP), and the configured app-event mapping.
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
            ['hook' => $hookId, 'payload' => $payload],
        ) ?? new StartResult(null, false);
    }

    /**
     * The mapped Laravel events: event class basename => trigger key (no class names leave
     * through this read either).
     *
     * @return array<string, string>
     */
    public function events(): array
    {
        $events = [];

        foreach (WorkflowsConfig::events() as $class => $mapping) {
            $events[class_basename($class)] = $mapping['key'];
        }

        return $events;
    }
}
