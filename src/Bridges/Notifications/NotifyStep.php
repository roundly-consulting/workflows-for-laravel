<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Workflows\Contracts\ResolvesNotificationContext;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Steps\Builtin\BuiltinStep;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\PartyValidator;
use RoundlyConsulting\Workflows\Support\Read;
use RoundlyConsulting\Workflows\Support\Values;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * `{notification, recipients, data?, actor?}`: sends a notifications-for-laravel type to the
 * resolved parties, with the step's idempotency key (a retry is a no-op per recipient). Nobody to
 * notify completes with `recipients: 0`. Inbox items are filed under the context
 * `workflows.bridges.notifications.context` names: the instance owner by default.
 *
 * @internal
 */
final class NotifyStep extends BuiltinStep
{
    public function __construct(
        private readonly NotificationsManager $notifications,
    ) {}

    public function key(): string
    {
        return 'notify';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['notification', 'recipients'],
            'additionalProperties' => false,
            'properties' => [
                'notification' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'recipients' => ['$ref' => '#/$defs/parties'],
                'data' => ['type' => 'object'],
                'actor' => ['enum' => ['triggered_by', null]],
            ],
        ];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        return $this->validateAction($config, $scope, '');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<ValidationError>
     */
    public function validateAction(array $config, ValidationScope $scope, string $path): array
    {
        $errors = PartyValidator::validate($config['recipients'] ?? null, $scope, $path.'/recipients');
        $type = $config['notification'] ?? null;

        if (! is_string($type) || ! $this->notifications->types()->has($type)) {
            $errors[] = ValidationError::make(ValidationCode::UnknownNotificationType, node: $scope->node?->id, path: $path.'/notification', params: ['notification' => is_string($type) ? $type : '']);
        }

        return $errors;
    }

    public function handle(StepContext $context): StepResult
    {
        $count = $this->send($context, $context->config(), $context->idempotencyKey());

        return StepResult::complete(output: ['recipients' => $count]);
    }

    public function simulate(StepContext $context): StepResult
    {
        $type = Read::string($context->config(), 'notification');

        return StepResult::complete(output: ['recipients' => null])->wouldDo('notify '.$type, ['notification' => $type]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function send(StepContext $context, array $config, string $key): int
    {
        $recipients = $context->parties(PartyReference::listFrom($config['recipients'] ?? []));

        if ($recipients === []) {
            return 0;
        }

        $data = Values::resolveMap(is_array($config['data'] ?? null) ? $config['data'] : [], $context->scope());
        $actor = ($config['actor'] ?? null) === 'triggered_by' ? $context->scope()->starter : null;

        $this->notifications->send(
            Read::string($config, 'notification'),
            $context->subject(),
            $recipients,
            array_map(static fn (mixed $value): mixed => is_scalar($value) || $value === null || is_array($value) ? $value : (string) json_encode($value), $data),
            $actor,
            $this->context($context),
            $key,
        );

        return count($recipients);
    }

    /**
     * The inbox context: the instance owner (`owner`), none, or the configured resolver's. An
     * owner whose key the notifications context columns cannot hold (`notifications.context_key_type`,
     * else `notifications.key_type`) goes without one, as before 1.3, rather than failing the send.
     */
    private function context(StepContext $context): Context|Model|string|null
    {
        $mode = WorkflowsConfig::notificationsContext();
        $owner = $context->owner();

        if ($mode === null) {
            return null;
        }

        if ($mode === 'owner') {
            return $owner !== null && self::storable($owner) ? $owner : null;
        }

        $resolver = app($mode);
        $resolved = $resolver instanceof ResolvesNotificationContext ? $resolver->notificationContext($context->instance(), $owner, $context->subject()) : null;

        if ($resolved === null || is_string($resolved) || $resolved instanceof Context || $resolved instanceof Model) {
            return $resolved;
        }

        throw InvalidWorkflowsConfigurationException::invalidMap('workflows.bridges.notifications.context', 'a resolver returning a notifications Context, a model, a context key or null');
    }

    private static function storable(Model $owner): bool
    {
        $type = KeyType::fromConfig('notifications.context_key_type', KeyType::fromConfig('notifications.key_type'));
        $key = $owner->getKey();

        return match ($type) {
            KeyType::BigInt => is_int($key) || (is_string($key) && preg_match('/^[1-9]\d{0,18}$/', $key) === 1),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };
    }
}
