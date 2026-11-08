<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\DataTransferObjects\Duration;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\DurationUnit;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Steps\WaitSpec;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Read;

/**
 * Waits for an app event (`event`, matched to the instance's subject unless `match: any`) or for
 * a condition to hold (`condition`, re-evaluated when the subject is touched and every `poll`),
 * with an optional `timeout` → `timed_out`. A condition already true proceeds at once. Supports
 * escalations.
 *
 * @internal
 */
final class WaitStep extends BuiltinStep
{
    public function key(): string
    {
        return 'wait';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function supportsEscalations(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'event' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'match' => ['enum' => ['subject', 'any']],
                'where' => ['$ref' => '#/$defs/condition'],
                'condition' => ['$ref' => '#/$defs/condition'],
                'poll' => ['$ref' => '#/$defs/duration'],
                'timeout' => ['$ref' => '#/$defs/duration'],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        $outcomes = [array_key_exists('condition', $config) ? Outcome::of('met') : Outcome::of('received')];

        if (array_key_exists('timeout', $config)) {
            $outcomes[] = Outcome::of('timed_out');
        }

        return $outcomes;
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $node = $scope->node?->id;

        if (array_key_exists('event', $config) === array_key_exists('condition', $config)) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, params: ['keyword' => 'oneOf', 'expected' => 'event | condition'])];
        }

        if (is_string($config['event'] ?? null) && preg_match(TriggerStep::EVENT_KEY, $config['event']) !== 1) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/event', params: ['keyword' => 'pattern', 'expected' => 'a dotted event key'])];
        }

        if (array_key_exists('poll', $config)) {
            $poll = Durations::parse($config['poll']);

            if ($poll === null || $poll->isBusiness() || self::seconds($poll) < 60) {
                return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/poll', params: ['keyword' => 'minimum', 'expected' => 'PT1M'])];
            }
        }

        if (array_key_exists('where', $config)) {
            if (! array_key_exists('event', $config)) {
                return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/where', params: ['keyword' => 'dependentRequired', 'expected' => 'event'])];
            }

            return ConditionValidator::validate($config['where'], $scope, '/where');
        }

        return array_key_exists('condition', $config) ? ConditionValidator::validate($config['condition'], $scope, '/condition') : [];
    }

    public function handle(StepContext $context): StepResult
    {
        $config = $context->config();

        if (is_array($config['condition'] ?? null)) {
            if (Evaluator::evaluate($config['condition'], $context->scope())) {
                return StepResult::complete('met');
            }

            $poll = array_key_exists('poll', $config) ? Durations::parse($config['poll']) : null;
            $spec = WaitSpec::condition(GraphParser::stringKeyed($config['condition']), $poll ?? new Duration(amount: 15, unit: DurationUnit::Minutes));
        } else {
            $match = ['subject' => ($config['match'] ?? 'subject') !== 'any'];

            if (is_array($config['where'] ?? null)) {
                $match['where'] = GraphParser::stringKeyed($config['where']);
            }

            $spec = WaitSpec::event(Read::string($config, 'event'), $match);
        }

        $timeout = array_key_exists('timeout', $config) ? Durations::parse($config['timeout']) : null;

        return StepResult::wait($timeout === null ? $spec : $spec->timeout($timeout));
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        return match ($signal->kind) {
            SignalKind::Event => StepResult::complete('received', ['payload' => $signal->payload]),
            SignalKind::Condition => StepResult::complete('met'),
            default => parent::resume($context, $signal),
        };
    }

    public function simulate(StepContext $context): StepResult
    {
        $config = $context->config();

        if (is_array($config['condition'] ?? null) && Evaluator::evaluate($config['condition'], $context->scope())) {
            return StepResult::complete('met');
        }

        return StepResult::complete(array_key_exists('condition', $config) ? 'met' : 'received');
    }

    private static function seconds(Duration $duration): int
    {
        if ($duration->iso !== null) {
            return (int) (Durations::iso($duration->iso)->totalSeconds ?? 0);
        }

        return match ($duration->unit) {
            DurationUnit::Minutes => $duration->amount * 60,
            DurationUnit::Hours => $duration->amount * 3600,
            default => $duration->amount * 86400,
        };
    }
}
