<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Notifications;

use Closure;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Exceptions\InvalidRecipientException;
use RoundlyConsulting\Notifications\Exceptions\MissingAccessCheckException;
use RoundlyConsulting\Notifications\Exceptions\UnknownNotificationTypeException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\EscalationActions;
use Throwable;

/**
 * notifications-for-laravel, when installed: the `notify` step and the `notify` escalation
 * action. Its permanent refusals (a type without an access check, a wrong subject, bad data, an
 * unknown type) fail the step without burning the retry budget. Pulled in lazily by the
 * registries, so a fresh registry (a restart, Octane) never loses it.
 *
 * @internal
 */
final class NotificationsBridge
{
    public static function escalationActions(EscalationActions $actions): void
    {
        $actions->register(
            'notify',
            static function (StepContext $context, array $config, string $key): void {
                app(NotifyStep::class)->send($context, $config, $key);
            },
            static fn (array $config, ValidationScope $scope, string $path): array => app(NotifyStep::class)->validateAction($config, $scope, $path),
        );
    }

    /**
     * @return Closure(Throwable): ?StepFailure
     */
    public static function exceptions(): Closure
    {
        return static function (Throwable $exception): ?StepFailure {
            $permanent = $exception instanceof MissingAccessCheckException
                || $exception instanceof InvalidNotificationSubjectException
                || $exception instanceof UnknownNotificationTypeException
                || $exception instanceof InvalidNotificationDataException
                || $exception instanceof InvalidRecipientException;

            return $permanent ? StepFailure::permanent('notification_refused:'.$exception->errorCode(), $exception->getMessage()) : null;
        };
    }
}
