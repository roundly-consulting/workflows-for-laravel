<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;
use RoundlyConsulting\Workflows\Exceptions\StepAuthorizationDeniedException;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Support\Clock;
use Throwable;

/**
 * Turns what a handler threw into a step failure: the handler-facing exceptions keep their codes,
 * the package's own are permanent, bridges map their providers' permanent errors (configuration,
 * usage, wrong subject) to permanent codes, and anything else is a retryable `exception` whose
 * message is stored for operators but never rendered over the wire.
 *
 * @internal
 */
final class ExceptionMap
{
    /** @var list<Closure(Throwable): ?StepFailure> */
    private array $mappers = [];

    /**
     * @param  Closure(Throwable): ?StepFailure  $mapper
     */
    public function register(Closure $mapper): void
    {
        $this->mappers[] = $mapper;
    }

    public function map(Throwable $exception): StepFailure
    {
        foreach ($this->mappers as $mapper) {
            $failure = $mapper($exception);

            if ($failure !== null) {
                return $failure;
            }
        }

        return match (true) {
            $exception instanceof StepFailedException => StepFailure::permanent($exception->failureCode(), $exception->getMessage()),
            $exception instanceof RetryableStepException => StepFailure::transient(
                $exception->failureCode(),
                $exception->getMessage(),
                $exception->retryAfter() === null ? null : Clock::utc($exception->retryAfter()),
            ),
            $exception instanceof StepAuthorizationDeniedException => StepFailure::permanent('authorization_denied', $exception->getMessage()),
            $exception instanceof BusinessCalendarNeverOpenException => StepFailure::permanent('calendar_never_open', $exception->getMessage()),
            $exception instanceof UnknownCalendarException => StepFailure::permanent('calendar_missing', $exception->getMessage()),
            $exception instanceof WorkflowsException => StepFailure::permanent($exception->errorCode(), $exception->getMessage()),
            default => StepFailure::transient('exception', $exception->getMessage()),
        };
    }
}
