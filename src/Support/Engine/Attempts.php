<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Support\Authorization;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\StepInputs;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use Throwable;

/**
 * One attempt of a claimed step, outside the instance lock: the subject is loaded inside the
 * owner context and checked against the owner (a mismatch fails before the handler runs), the
 * authorization hook runs for side-effect types, then the handler (or `resume()`). Transactional
 * built-ins hand back a closure the recorder runs inside its transaction.
 *
 * @internal
 */
final readonly class Attempts
{
    public function __construct(
        private StepCatalogue $catalogue,
        private ExceptionMap $exceptions,
        private Owners $owners,
        private Authorization $authorization,
    ) {}

    /**
     * @return array{0: StepResult|StepFailure|null, 1: (Closure(): (StepResult|StepFailure))|null}
     */
    public function run(WorkflowInstance $instance, StepRun $run, ?Signal $signal = null): array
    {
        $version = $instance->version;

        if (! $version instanceof WorkflowVersion) {
            $this->recordInput($run, $signal, StepInputs::unresolved(null));

            return [StepFailure::permanent(FailureCode::VersionMissing, Messages::get('failures.version_missing')), null];
        }

        $graph = $version->parsedGraph();
        $node = $graph->node($run->node_id);
        $type = $node === null ? null : $this->catalogue->find($node->type);

        if ($node === null || $type === null) {
            $this->recordInput($run, $signal, StepInputs::unresolved($node));
            $package = $node === null ? null : $this->catalogue->missingAdapter($node->type);

            return [$package === null
                ? StepFailure::permanent(FailureCode::UnknownStepType, Messages::get('failures.unknown_step_type', ['type' => $node->type ?? $run->node_type]))
                : StepFailure::permanent(FailureCode::AdapterMissing, Messages::get('failures.adapter_missing', ['package' => $package])), null];
        }

        $owner = Runtime::owner($instance);
        $subject = null;

        if ($instance->subject_type !== null) {
            $subject = Runtime::subject($instance);

            if ($subject === null) {
                $this->recordInput($run, $signal, StepInputs::unresolved($node));

                return [StepFailure::permanent(FailureCode::SubjectMissing, Messages::get('failures.subject_missing')), null];
            }

            if ($instance->owner_type !== null && ! Owners::same($owner, $this->owners->ownerOf($subject))) {
                $this->recordInput($run, $signal, StepInputs::unresolved($node));

                return [StepFailure::permanent(FailureCode::OwnerMismatch, Messages::get('failures.owner_mismatch')), null];
            }
        }

        $context = Runtime::context($instance, $run, $version, $graph, $node, $subject, $owner, $signal);
        $this->recordInput($run, $signal, $context->resolvedConfig());

        if ($type->hasSideEffects()) {
            try {
                $ability = $type->abilityFor($context);
                $this->authorization->check($context->actor(), $ability, $subject, $node, $instance);
            } catch (Throwable $exception) {
                return [$this->exceptions->map($exception), null];
            }
        }

        if ($type->transactional()) {
            return [null, fn (): StepResult|StepFailure => $this->transactional($type, $context, $signal)];
        }

        return [$this->invoke($type, $context, $signal), null];
    }

    /**
     * The first entry into an attempt records what it saw; a resume continues that attempt and
     * keeps it. Refs resolve per attempt, so a retry records the values of its own start.
     *
     * @param  array<string, mixed>  $input
     */
    private function recordInput(StepRun $run, ?Signal $signal, array $input): void
    {
        if ($signal !== null) {
            return;
        }

        $run->input = $input;
        $run->save();
    }

    public function invoke(StepType $type, StepContext $context, ?Signal $signal): StepResult|StepFailure
    {
        try {
            return $signal === null ? $type->handle($context) : $type->resume($context, $signal);
        } catch (Throwable $exception) {
            return $this->exceptions->map($exception);
        }
    }

    /**
     * A transactional handler's writes roll back on its own failure (a savepoint), so a failed
     * attempt never commits half its effect alongside the failure record.
     */
    private function transactional(StepType $type, StepContext $context, ?Signal $signal): StepResult|StepFailure
    {
        $model = WorkflowModels::instance();
        $connection = (new $model)->getConnection();

        try {
            return $connection->transaction(static fn (): StepResult => $signal === null ? $type->handle($context) : $type->resume($context, $signal));
        } catch (Throwable $exception) {
            return $this->exceptions->map($exception);
        }
    }
}
