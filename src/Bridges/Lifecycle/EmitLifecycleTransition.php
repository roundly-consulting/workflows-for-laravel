<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Lifecycle;

use BackedEnum;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * With `workflows.bridges.lifecycle.emit_transitions` on, every lifecycle state change (after
 * its transaction commits) emits `lifecycle.<lifecycle>.<transition>` about the subject — an
 * expiry or adoption, which has no transition name, uses its kind. The history id is the
 * idempotency key, so a redelivered event starts nothing twice.
 *
 * @internal
 */
final readonly class EmitLifecycleTransition
{
    public function __construct(
        private WorkflowsManager $workflows,
    ) {}

    public function handle(LifecycleTransitioned $event): void
    {
        if (! WorkflowsConfig::lifecycleEmitTransitions()) {
            return;
        }

        $key = 'lifecycle.'.self::segment($event->lifecycle).'.'.self::segment($event->transition ?? $event->kind->value);

        $this->workflows->emit(
            $key,
            $event->subject,
            [
                'lifecycle' => $event->lifecycle,
                'transition' => $event->transition,
                'kind' => $event->kind->value,
                'from' => $event->from instanceof BackedEnum ? $event->from->value : $event->from,
                'to' => $event->to instanceof BackedEnum ? $event->to->value : $event->to,
                'history_id' => $event->historyId,
            ],
            'lifecycle:'.$event->historyId,
            $event->actor,
        );
    }

    /**
     * One event-key segment: lowercase letters, digits and underscores.
     */
    private static function segment(string $value): string
    {
        $segment = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)), '_');

        return $segment === '' ? 'unnamed' : $segment;
    }
}
