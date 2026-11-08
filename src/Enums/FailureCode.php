<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * Every failure code the engine, the built-in steps and the bridges write to
 * `workflow_instances.failure_code` / `workflow_step_runs.error_code`. Two families carry a detail
 * after a colon (`transition_denied:guard_failed`, `notification_refused:<code>`); a host step's
 * own codes stay plain strings. `fromStored()` reads either.
 */
enum FailureCode: string
{
    use Helpers;

    case AdapterMissing = 'adapter_missing';
    case ApprovalCancelled = 'approval_cancelled';
    case ApprovalClosed = 'approval_closed';
    case ApprovalMissing = 'approval_missing';
    case AuthorizationDenied = 'authorization_denied';
    case CalendarMissing = 'calendar_missing';
    case CalendarNeverOpen = 'calendar_never_open';
    case ChildNotStarted = 'child_not_started';
    case DelayFieldEmpty = 'delay_field_empty';
    case EndedByGraph = 'ended_by_graph';
    case Exception = 'exception';
    case FieldWriteRefused = 'field_write_refused';
    case IdempotencyConflict = 'idempotency_conflict';
    case InvalidApprovalRequest = 'invalid_approval_request';
    case InvalidOutput = 'invalid_output';
    case InvalidValue = 'invalid_value';
    case LeaseExpired = 'lease_expired';
    case MissingEdge = 'missing_edge';
    case MissingOutcomeEdge = 'missing_outcome_edge';
    case MissingTrigger = 'missing_trigger';
    case NestingDepthExceeded = 'nesting_depth_exceeded';
    case NoApprovers = 'no_approvers';
    case NoResult = 'no_result';
    case NotificationRefused = 'notification_refused';
    case OwnerMismatch = 'owner_mismatch';
    case ResumeUnsupported = 'resume_unsupported';
    case RolesUnavailable = 'roles_unavailable';
    case StartRateExceeded = 'start_rate_exceeded';
    case StepLimitExceeded = 'step_limit_exceeded';
    case StepMissing = 'step_missing';
    case SubjectMissing = 'subject_missing';
    case TransitionConflict = 'transition_conflict';
    case TransitionDenied = 'transition_denied';
    case TransitionUnusable = 'transition_unusable';
    case UnknownApprovalPreset = 'unknown_approval_preset';
    case UnknownEscalationAction = 'unknown_escalation_action';
    case UnknownStepType = 'unknown_step_type';
    case VersionMissing = 'version_missing';
    case WorkerTimeout = 'worker_timeout';

    /**
     * A stored code as its case (a `code:detail` code by its prefix), or the string itself for a
     * host step's own code.
     */
    public static function fromStored(string $stored): self|string
    {
        return self::tryFrom(explode(':', $stored, 2)[0]) ?? $stored;
    }

    /**
     * The detail after the colon (`guard_failed` in `transition_denied:guard_failed`), if any.
     */
    public static function detail(string $stored): ?string
    {
        $parts = explode(':', $stored, 2);

        return isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
    }

    /**
     * Whether retrying the instance can help: the cause may have gone away (a lock, a lease, a
     * worker, an outside error) or an operator can fix it before retrying (a missing package,
     * calendar, role or subject).
     */
    public function retryable(): bool
    {
        return in_array($this, [
            self::Exception, self::LeaseExpired, self::WorkerTimeout, self::TransitionConflict,
            self::AdapterMissing, self::CalendarMissing, self::RolesUnavailable, self::AuthorizationDenied,
            self::NoApprovers, self::DelayFieldEmpty, self::SubjectMissing, self::OwnerMismatch,
            self::UnknownStepType, self::ChildNotStarted,
        ], true);
    }

    /**
     * The translated default message.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public function message(array $replace = []): string
    {
        return Messages::get('failures.'.$this->value, $replace);
    }
}
