<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Notifications;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\InvalidRecipientException;
use RoundlyConsulting\Notifications\Exceptions\MissingAccessCheckException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\Engine\ExceptionMap;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\EscalationActions;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use Throwable;

/**
 * notifications-for-laravel, when installed: the `notify` step and the `notify` escalation
 * action. Its permanent refusals (a type without an access check, a wrong subject, bad data, an
 * unknown type) fail the step without burning the retry budget.
 *
 * @internal
 */
final class NotificationsBridge
{
    public static function register(Application $app): void
    {
        if (! Bridges::available('notifications')) {
            return;
        }

        $catalogue = $app->make(StepCatalogue::class);
        $actions = $app->make(EscalationActions::class);
        $map = $app->make(ExceptionMap::class);

        $catalogue->registerBuiltin(NotifyStep::class);

        $actions->register(
            'notify',
            static function (StepContext $context, array $config, string $key): void {
                app(NotifyStep::class)->send($context, $config, $key);
            },
            static fn (array $config, ValidationScope $scope, string $path): array => app(NotifyStep::class)->validateAction($config, $scope, $path),
        );

        $map->register(static function (Throwable $exception): ?StepFailure {
            $permanent = $exception instanceof MissingAccessCheckException
                || $exception instanceof InvalidNotificationSubjectException
                || $exception instanceof UnknownNotificationTypeException
                || $exception instanceof InvalidNotificationDataException
                || $exception instanceof InvalidRecipientException;

            if (! $permanent) {
                return null;
            }

            $code = $exception->errorCode();

            return StepFailure::permanent('notification_refused:'.$code, $exception->getMessage());
        });
    }

    /**
     * @internal
     *
     * @return list<PartyReference>
     */
    public static function parties(mixed $payload): array
    {
        return PartyReference::listFrom($payload);
    }
}
