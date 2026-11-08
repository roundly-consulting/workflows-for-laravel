<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Keys;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * Delivers an event key to the pending `event` waits listening for it. App events only reach
 * waits of instances with the same owner (and, unless the wait says `match: any`, the same
 * subject); internal `@` keys (`@approvals.request.42`, `@instance.<ulid>.ended`) are unique and
 * skip both checks. Each wait is claimed with a compare-and-set before its resume is queued, so a
 * duplicate signal resumes it once.
 *
 * @internal
 */
final readonly class Signals
{
    public function __construct(
        private EngineQueue $queue,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $key, ?Model $subject, array $payload, ?Model $owner, bool $internal = false): int
    {
        $model = WorkflowModels::wait();
        $waits = $model::query()
            ->where('status', WaitStatus::Pending->value)
            ->where('kind', WaitKind::Event->value)
            ->where('event_key', $key)
            ->with('instance')
            ->get();

        $resumed = 0;

        foreach ($waits as $wait) {
            if (! $wait->instance instanceof WorkflowInstance) {
                continue;
            }

            if (! $internal && ! $this->reaches($wait, $wait->instance, $subject, $owner)) {
                continue;
            }

            $token = (string) Str::ulid();

            $claimed = $model::query()
                ->whereKey(Keys::of($wait))
                ->where('status', WaitStatus::Pending->value)
                ->update([
                    'status' => WaitStatus::Claimed->value,
                    'claim_token' => $token,
                    'claimed_at' => Clock::format(Clock::now()),
                    'payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => Clock::format(Clock::now()),
                ]);

            if ($claimed === 1) {
                $resumed++;
                $this->queue->resume($wait->step_run_id, Keys::of($wait), SignalKind::Event);
            }
        }

        return $resumed;
    }

    private function reaches(WorkflowWait $wait, WorkflowInstance $instance, ?Model $subject, ?Model $owner): bool
    {
        $instanceOwner = $instance->owner_type === null ? null : Runtime::owner($instance);

        if ($instance->owner_type !== null && $instanceOwner === null) {
            return false;
        }

        if (! Owners::same($instanceOwner, $owner)) {
            return false;
        }

        $matchSubject = ($wait->match['subject'] ?? true) !== false;

        if (! $matchSubject) {
            return true;
        }

        if ($subject === null) {
            return $instance->subject_type === null;
        }

        return $instance->subject_type === $subject->getMorphClass() && (string) $instance->subject_id === (string) Keys::of($subject);
    }
}
