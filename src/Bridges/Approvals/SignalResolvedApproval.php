<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Approvals;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\WorkflowsManager;

/**
 * A round resolved: signal the step waiting on it. After the commit only, so it never locks an
 * instance from inside the decider's transaction and never resumes on a decision that rolls
 * back. It only enqueues; the resume reads the round itself.
 *
 * @internal
 */
final readonly class SignalResolvedApproval implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private WorkflowsManager $workflows,
    ) {}

    public function handle(ApprovalRequestResolved $event): void
    {
        $this->workflows->signal('@approvals.request.'.Keys::of($event->request));
    }
}
