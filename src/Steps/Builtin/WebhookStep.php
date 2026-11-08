<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\Bridges\Webhooks\WebhooksBridge;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Support\Read;
use RoundlyConsulting\Workflows\Support\Values;

/**
 * `{endpoint, event, payload?}`: queues an outbound webhook through the DeliversWebhooks
 * implementation, once per visit (outcome `queued`). Registered when one is available: a host
 * binding, `workflows.adapters.webhooks`, or the webhooks-for-laravel bridge.
 *
 * @internal
 */
final class WebhookStep extends BuiltinStep
{
    public function key(): string
    {
        return 'webhook';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['endpoint', 'event'],
            'additionalProperties' => false,
            'properties' => [
                'endpoint' => ['type' => ['string', 'integer', 'null'], 'x-workflows-owner-specific' => true],
                'event' => ['type' => 'string', 'maxLength' => 100],
                'payload' => ['type' => 'object'],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('queued')];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        if (is_string($config['event'] ?? null) && preg_match(TriggerStep::EVENT_KEY, $config['event']) !== 1) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $scope->node?->id, path: '/event', params: ['keyword' => 'pattern', 'expected' => 'a dotted event key'])];
        }

        return WebhooksBridge::validateEvent($config['event'] ?? null, $scope->node?->id);
    }

    public function handle(StepContext $context): StepResult
    {
        $config = $context->config();
        $payload = Values::resolveMap(is_array($config['payload'] ?? null) ? $config['payload'] : [], $context->scope());
        $deliverer = Adapters::webhooks();

        $delivery = $context->once('delivery', static fn (): int|string => $deliverer instanceof DeliversWebhooks
            ? $deliverer->deliver($context->owner(), Read::string($config, 'endpoint'), Read::string($config, 'event'), $payload, $context->idempotencyKey())
            : '');

        return StepResult::complete('queued', ['delivery' => is_int($delivery) || is_string($delivery) ? $delivery : null]);
    }
}
