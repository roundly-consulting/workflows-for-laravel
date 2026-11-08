<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;
use RoundlyConsulting\Workflows\Bridges\Approvals\ApprovalsBridge;
use RoundlyConsulting\Workflows\Bridges\Lifecycle\LifecycleBridge;
use RoundlyConsulting\Workflows\Bridges\Notifications\NotificationsBridge;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Exceptions\BusinessCalendarNeverOpenException;
use RoundlyConsulting\Workflows\Exceptions\StepAuthorizationDeniedException;
use RoundlyConsulting\Workflows\Exceptions\UnknownCalendarException;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Support\Bridges;
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

    private bool $loaded = false;

    /**
     * @param  Closure(Throwable): ?StepFailure  $mapper
     */
    public function register(Closure $mapper): void
    {
        $this->load();
        $this->mappers[] = $mapper;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (Bridges::available('approvals')) {
            $this->mappers[] = ApprovalsBridge::exceptions();
        }

        if (Bridges::available('lifecycle')) {
            $this->mappers[] = LifecycleBridge::exceptions();
        }

        if (Bridges::available('notifications')) {
            $this->mappers[] = NotificationsBridge::exceptions();
        }
    }

    public function map(Throwable $exception): StepFailure
    {
        $this->load();

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
            $exception instanceof StepAuthorizationDeniedException => StepFailure::permanent(FailureCode::AuthorizationDenied, $exception->getMessage()),
            $exception instanceof BusinessCalendarNeverOpenException => StepFailure::permanent(FailureCode::CalendarNeverOpen, $exception->getMessage()),
            $exception instanceof UnknownCalendarException => StepFailure::permanent(FailureCode::CalendarMissing, $exception->getMessage()),
            $exception instanceof WorkflowsException => StepFailure::permanent($exception->errorCode(), $exception->getMessage()),
            default => StepFailure::transient(FailureCode::Exception, $exception->getMessage()),
        };
    }
}
