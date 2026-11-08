<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps;

use RoundlyConsulting\Workflows\DataTransferObjects\PartyReference;
use RoundlyConsulting\Workflows\DataTransferObjects\RetryPolicy;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * A step type: its catalogue definition (key, label, config schema, outcomes) and its handler.
 * Hosts extend it for domain steps (create a task, add a comment) and register the class
 * (`config('workflows.steps')` or `Workflows::catalogue()->register()`). Host keys must contain
 * a dot (`acme.create_task`).
 *
 * The handler contract (docs: step-handler-contract): `handle()` is called at least once per
 * attempt; make every side effect idempotent on `$context->idempotencyKey()` (or wrap it in
 * `$context->once()`); never sleep or block (return `StepResult::wait()`); return plain-JSON
 * output; fail with `StepFailedException` (permanent) or `RetryableStepException` (transient).
 */
abstract class StepType
{
    abstract public function key(): string;

    abstract public function label(): string;

    /**
     * The JSON Schema (draft 2020-12 subset) of the node config. `#/$defs/{value, party, parties,
     * duration, condition, field}` are provided by the package.
     *
     * @return array<string, mixed>
     */
    abstract public function configSchema(): array;

    /**
     * Side-effect types get the authorization hook, retries, idempotency keys and simulation in
     * dry runs.
     */
    abstract public function hasSideEffects(): bool;

    abstract public function handle(StepContext $context): StepResult;

    public function description(): ?string
    {
        return null;
    }

    public function category(): string
    {
        return 'custom';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<Outcome>
     */
    public function outcomes(array $config): array
    {
        return [Outcome::next()];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function outputSchema(): ?array
    {
        return null;
    }

    /**
     * The policy ability the default authorizer checks on the subject before `handle()`.
     */
    public function ability(): ?string
    {
        return null;
    }

    public function retry(): ?RetryPolicy
    {
        return null;
    }

    public function supportsEscalations(): bool
    {
        return false;
    }

    /**
     * Semantic checks past the schema (a transition that does not exist, an unknown type key).
     *
     * @param  array<string, mixed>  $config
     * @return list<ValidationError>
     */
    public function validateConfig(array $config, ValidationScope $scope): array
    {
        return [];
    }

    /**
     * JSON pointers (within the node config) cleared and flagged when a template is imported for
     * another owner. Paths marked `x-workflows-owner-specific: true` in the schema count too.
     *
     * @return list<string>
     */
    public function ownerSpecificPaths(): array
    {
        return [];
    }

    /**
     * Called when one of the step's waits fires. The default completes a timer (`next`) and a
     * timeout (its outcome); types that wait on anything else override it.
     */
    public function resume(StepContext $context, Signal $signal): StepResult
    {
        return match ($signal->kind) {
            SignalKind::Timeout => StepResult::complete($signal->outcome ?? 'timed_out'),
            SignalKind::Timer => StepResult::complete(),
            default => throw StepFailedException::because('resume_unsupported', Messages::get('failures.resume_unsupported', ['type' => $this->key()])),
        };
    }

    /**
     * The dry-run form of the step. Must be pure. Null (the default) lists a side-effect step as
     * "would run" and takes an assumed outcome.
     */
    public function simulate(StepContext $context): ?StepResult
    {
        return null;
    }

    /**
     * Clean-up when the instance is cancelled while this step waits.
     */
    public function cancel(StepContext $context): void {}

    /**
     * The `reassign` escalation action. Types that support it override this and `supportsReassign()`.
     *
     * @param  list<PartyReference>  $to
     */
    public function reassign(StepContext $context, array $to): ?StepResult
    {
        return null;
    }

    public function supportsReassign(): bool
    {
        return false;
    }

    /**
     * Whether the handler runs inside the transaction that records its result (DB-only built-ins).
     *
     * @internal
     */
    public function transactional(): bool
    {
        return false;
    }
}
