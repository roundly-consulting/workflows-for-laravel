<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Exceptions\ConcurrentTransitionException;
use RoundlyConsulting\Lifecycle\Exceptions\IdempotencyConflictException;
use RoundlyConsulting\Lifecycle\Exceptions\InvalidLifecycleUsageException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectNotPersistedException;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\Lifecycle\Exceptions\UnknownLifecycleException;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Workflows\Contracts\TransitionAdapter;
use RoundlyConsulting\Workflows\DataTransferObjects\Actor;
use RoundlyConsulting\Workflows\DataTransferObjects\TransitionOutcome;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use Throwable;

/**
 * Requests a transition through `Lifecycles::for($subject)->by($user)|asSystem()->because()
 * ->idempotencyKey()->attempt()`: lifecycle's guards, actor rules and idempotent replay apply.
 * A lost race is retryable; usage errors (an unknown lifecycle, a trashed or unsaved subject)
 * and an idempotency conflict are permanent.
 *
 * @internal
 */
final readonly class LifecycleTransitionAdapter implements TransitionAdapter
{
    public function __construct(
        private LifecycleManager $lifecycles,
    ) {}

    public function attempt(Model $subject, ?string $lifecycle, string $transition, Actor $actor, string $idempotencyKey, string $reason): TransitionOutcome
    {
        try {
            $handle = $this->lifecycles->for($subject, $lifecycle);
            $handle = $actor->isSystem() || $actor->user === null ? $handle->asSystem() : $handle->by($actor->user);

            $attempt = $handle
                ->because(Str::limit($reason, Config::integer('lifecycle.history.reason_max_length', 1000, min: 1), ''))
                ->idempotencyKey(Str::limit($idempotencyKey, 191, ''))
                ->attempt($transition);
        } catch (IdempotencyConflictException $exception) {
            throw StepFailedException::because(FailureCode::IdempotencyConflict, $exception->getMessage());
        } catch (ConcurrentTransitionException $exception) {
            throw RetryableStepException::because(FailureCode::TransitionConflict, $exception->getMessage());
        } catch (InvalidLifecycleUsageException|UnknownLifecycleException|SubjectTrashedException|SubjectNotPersistedException $exception) {
            throw StepFailedException::because(FailureCode::TransitionUnusable, $exception->getMessage());
        }

        if ($attempt->succeeded) {
            return TransitionOutcome::applied();
        }

        $denial = $attempt->decision->first();

        return TransitionOutcome::denied(
            $denial->code ?? 'denied',
            $denial->message ?? '',
            $attempt->decision->isRetryable(),
            $attempt->decision->retryAfter,
        );
    }

    public function hasTransition(string $subjectClass, ?string $lifecycle, string $transition): ?bool
    {
        if (! is_subclass_of($subjectClass, LifecycleSubject::class)) {
            return null;
        }

        try {
            return $this->lifecycles->model($subjectClass, $lifecycle)->definition()->hasTransition($transition);
        } catch (Throwable) {
            return false;
        }
    }

    public function stateAttributes(string $subjectClass): array
    {
        if (! is_subclass_of($subjectClass, LifecycleSubject::class)) {
            return [];
        }

        $subject = new $subjectClass;

        return array_map(strval(...), array_keys($subject->lifecycleDefinitions()));
    }
}
