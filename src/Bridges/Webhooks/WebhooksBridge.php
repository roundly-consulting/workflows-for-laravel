<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Webhooks;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Webhooks\Accessors\HandlerRegistry;
use RoundlyConsulting\Webhooks\DataTransferObjects\InboundWebhook;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotAcceptingException;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEventTypeException;
use RoundlyConsulting\Webhooks\Exceptions\PayloadTooLargeException;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\DataTransferObjects\WebhookDelivery;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\EndpointOwnerMismatchException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use Throwable;

/**
 * webhooks-for-laravel, when installed: the inbound handler alias (`workflows` by default) that
 * starts the workflow whose webhook trigger names the endpoint, and the default outbound
 * deliverer behind the `webhook` step. Signatures, replay protection, retries, SSRF and signing
 * stay that package's.
 *
 * @internal
 */
final class WebhooksBridge
{
    /** The webhooks package's event type rule (`EventTypes::valid()`). */
    private const string EVENT_TYPE = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/D';

    /**
     * Register the inbound handler alias, unless switched off or the host maps that alias itself
     * (`webhooks.handlers` or its own `register()`), which wins.
     */
    public static function register(Application $app): void
    {
        if (! Bridges::available('webhooks')) {
            return;
        }

        $alias = WorkflowsConfig::webhooksHandlerAlias();
        $handlers = $app->make(HandlerRegistry::class);

        if ($alias === null || $handlers->has($alias)) {
            return;
        }

        // A closure, not a class implementing InboundHandler: a class naming that interface can't
        // even be loaded (arch scans, reflection) when webhooks-for-laravel is not installed.
        $handlers->register($alias, self::receive(...));
    }

    /**
     * The `workflows` handler: a verified delivery starts the workflow whose webhook trigger names
     * the endpoint, with the delivery id as the idempotency key — a webhooks replay returns the
     * first instance and never runs the workflow again. A refusal (no workflow wired, another
     * owner's workflow) throws, so the delivery log shows it failed.
     *
     * @throws EndpointOwnerMismatchException
     */
    public static function receive(InboundWebhook $webhook): void
    {
        $endpoint = $webhook->endpoint;
        $owner = $webhook->owner();

        // An owned endpoint whose owner is gone never falls back to the app-wide workflows.
        if ($owner === null && ($endpoint->owner_type !== null || $endpoint->owner_id !== null)) {
            throw EndpointOwnerMismatchException::endpoint();
        }

        // Through the facade, so Workflows::fake() records it.
        Workflows::triggers()->delivery(new WebhookDelivery($endpoint->id, $owner, $webhook->deliveryId, $webhook->payload));
    }

    /**
     * The `webhook` step's deliverer when the host binds none.
     *
     * @return class-string<WebhooksDeliverer>|null
     */
    public static function defaultDeliverer(): ?string
    {
        return Bridges::available('webhooks') ? WebhooksDeliverer::class : null;
    }

    /**
     * Publish validation of a `webhook` step's event when the bridge delivers it: the webhooks
     * package's type rule (≤ 100 characters) and, when set, its `outbound.event_types` allow-list.
     *
     * @return list<ValidationError>
     */
    public static function validateEvent(mixed $event, ?string $node): array
    {
        if (! Adapters::webhooks() instanceof WebhooksDeliverer || ! is_string($event)) {
            return [];
        }

        if (strlen($event) > 100 || preg_match(self::EVENT_TYPE, $event) !== 1) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/event', params: ['keyword' => 'pattern', 'expected' => 'a dotted event key'])];
        }

        $known = self::knownEventTypes();

        return $known === [] || in_array($event, $known, true)
            ? []
            : [ValidationError::make(ValidationCode::UnknownWebhookEvent, node: $node, path: '/event', params: ['event' => $event])];
    }

    /**
     * @return Closure(Throwable): ?StepFailure
     */
    public static function exceptions(): Closure
    {
        return static fn (Throwable $exception): ?StepFailure => match (true) {
            $exception instanceof EndpointNotFoundException => StepFailure::permanent(FailureCode::EndpointNotFound, $exception->getMessage()),
            $exception instanceof EndpointNotAcceptingException => StepFailure::permanent(FailureCode::EndpointNotAccepting->value.':'.$exception->reason(), $exception->getMessage()),
            $exception instanceof InvalidEventTypeException => StepFailure::permanent(FailureCode::InvalidEventType, $exception->getMessage()),
            $exception instanceof PayloadTooLargeException => StepFailure::permanent(FailureCode::PayloadTooLarge, $exception->getMessage()),
            default => null,
        };
    }

    /**
     * The webhooks package's `outbound.event_types` allow-list (a list or a comma string; like its
     * own reader, only valid types count, and an empty list allows every type).
     *
     * @return list<string>
     */
    private static function knownEventTypes(): array
    {
        $known = config('webhooks.outbound.event_types');
        $known = is_string($known) ? array_map(trim(...), explode(',', $known)) : $known;

        return is_array($known)
            ? array_values(array_filter($known, static fn (mixed $type): bool => is_string($type) && strlen($type) <= 100 && preg_match(self::EVENT_TYPE, $type) === 1))
            : [];
    }
}
