<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Approvals;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use Throwable;

/**
 * approvals-for-laravel, when installed: the `approval` step (rules, stages, delegation,
 * expiry; outcomes approved / rejected / expired / cancelled), its `reassign` escalation, and the
 * listener that signals the waiting step when a round resolves — after the decider's transaction
 * commits.
 *
 * @internal
 */
final class ApprovalsBridge
{
    public static function register(Application $app): void
    {
        if (! Bridges::available('approvals')) {
            return;
        }

        $app->make(Dispatcher::class)->listen(ApprovalRequestResolved::class, [SignalResolvedApproval::class, 'handle']);
    }

    /**
     * @return Closure(Throwable): ?StepFailure
     */
    public static function exceptions(): Closure
    {
        return static fn (Throwable $exception): ?StepFailure => match (true) {
            $exception instanceof InvalidApprovalRequestException => StepFailure::permanent('invalid_approval_request', $exception->getMessage()),
            $exception instanceof UnknownWorkflowException => StepFailure::permanent('unknown_approval_preset', $exception->getMessage()),
            default => null,
        };
    }
}
