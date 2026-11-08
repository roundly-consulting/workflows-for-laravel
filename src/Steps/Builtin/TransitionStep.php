<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\Contracts\TransitionAdapter;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\Read;

/**
 * `{transition, lifecycle?, reason?}`: requests a lifecycle transition through the transition
 * adapter. The lifecycle's guard decides. Denied: the `denied` branch when the graph has one,
 * else the step fails `transition_denied:<code>` (retried only when the lifecycle says so).
 *
 * @internal
 */
final class TransitionStep extends BuiltinStep
{
    public function key(): string
    {
        return 'transition';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function available(): bool
    {
        return Adapters::transition() !== null;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['transition'],
            'additionalProperties' => false,
            'properties' => [
                'transition' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'lifecycle' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'reason' => ['type' => 'string', 'maxLength' => 500],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('transitioned'), Outcome::of('denied', required: false)];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        if ($scope->subjectType === null || ! $this->available() || ! is_string($config['transition'] ?? null)) {
            return [];
        }

        $lifecycle = is_string($config['lifecycle'] ?? null) ? $config['lifecycle'] : null;

        return $this->adapter()->hasTransition($scope->subjectType->model, $lifecycle, $config['transition']) === false
            ? [ValidationError::make(ValidationCode::UnknownTransition, node: $scope->node?->id, path: '/transition', params: ['transition' => $config['transition']])]
            : [];
    }

    public function handle(StepContext $context): StepResult
    {
        $subject = $context->subject();
        $config = $context->config();

        if ($subject === null) {
            throw StepFailedException::because('subject_missing', Messages::get('failures.subject_missing'));
        }

        $reason = is_string($config['reason'] ?? null) && $config['reason'] !== ''
            ? $config['reason']
            : sprintf('workflow %s v%d #%s', $context->definitionKey(), $context->version()->number, $context->instance()->ulid);

        $outcome = $this->adapter()->attempt(
            $subject,
            is_string($config['lifecycle'] ?? null) ? $config['lifecycle'] : null,
            Read::string($config, 'transition'),
            $context->actor(),
            $context->idempotencyKey(),
            $reason,
        );

        if ($outcome->applied) {
            return StepResult::complete('transitioned', ['transition' => Read::string($config, 'transition')]);
        }

        $code = (string) $outcome->deniedCode;

        if ($context->graph()->edgeFor($context->node()->id, 'denied') !== null) {
            return StepResult::complete('denied', ['code' => $code, 'message' => $outcome->deniedMessage]);
        }

        if ($outcome->retryable) {
            throw RetryableStepException::because('transition_denied:'.$code, (string) $outcome->deniedMessage, $outcome->retryAfter);
        }

        throw StepFailedException::because('transition_denied:'.$code, (string) $outcome->deniedMessage);
    }

    public function simulate(StepContext $context): ?StepResult
    {
        return null;
    }

    private function adapter(): TransitionAdapter
    {
        return Adapters::transition() ?? throw StepFailedException::because('adapter_missing', Messages::get('failures.adapter_missing', ['package' => 'lifecycle-for-laravel']));
    }
}
