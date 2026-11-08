<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Steps\WaitSpec;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\FieldReader;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\Read;

/**
 * Waits for a duration (`for`, calendar or business time), until an instant (`until`), or until
 * a subject date field plus an optional signed offset (`until_field`). A persisted timer, never
 * a sleeping process; a due time already past proceeds at once. A cycle breaker.
 *
 * @internal
 */
final class DelayStep extends BuiltinStep
{
    public function key(): string
    {
        return 'delay';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'for' => ['$ref' => '#/$defs/duration'],
                'until' => ['type' => 'string', 'format' => 'date-time'],
                'until_field' => ['$ref' => '#/$defs/field'],
                'offset' => ['$ref' => '#/$defs/duration'],
            ],
        ];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $node = $scope->node?->id;
        $modes = array_intersect(['for', 'until', 'until_field'], array_keys($config));

        if (count($modes) !== 1) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, params: ['keyword' => 'oneOf', 'expected' => 'for | until | until_field'])];
        }

        if (array_key_exists('offset', $config) && ! array_key_exists('until_field', $config)) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/offset', params: ['keyword' => 'offset', 'expected' => 'until_field'])];
        }

        if (array_key_exists('for', $config) && Durations::parse($config['for'])?->isNegative() === true) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/for', params: ['keyword' => 'minimum', 'expected' => '0'])];
        }

        if (is_string($config['until_field'] ?? null)) {
            $error = ConditionValidator::field($config['until_field'], $scope, '/until_field');

            if ($error !== null) {
                return [$error];
            }

            $type = ConditionValidator::typeOf($config['until_field'], $scope);

            if ($type !== FieldType::Date && $type !== FieldType::Datetime) {
                return [ValidationError::make(ValidationCode::InvalidValue, node: $node, path: '/until_field', params: ['field' => $config['until_field']])];
            }
        }

        return [];
    }

    public function handle(StepContext $context): StepResult
    {
        $due = $this->due($context);

        if ($due->lessThanOrEqualTo($context->now())) {
            return StepResult::complete(output: ['due_at' => $due->toIso8601String()]);
        }

        return StepResult::wait(WaitSpec::until($due), ['due_at' => $due->toIso8601String()]);
    }

    public function simulate(StepContext $context): StepResult
    {
        $due = $this->due($context);

        return StepResult::complete(output: ['due_at' => $due->toIso8601String()]);
    }

    public function due(StepContext $context): CarbonImmutable
    {
        $config = $context->config();
        $now = $context->now();

        if (array_key_exists('for', $config)) {
            $duration = Durations::parse($config['for']);

            return $duration === null ? $now : Durations::add($duration, $now, $context->timezone(), $context->defaultCalendar(), $context->owner());
        }

        if (is_string($config['until'] ?? null)) {
            return Clock::utc(CarbonImmutable::parse($config['until'], $context->timezone()));
        }

        $value = $context->field(Read::string($config, 'until_field'));
        $date = FieldReader::date($value, $context->timezone());

        if ($date === null) {
            throw StepFailedException::because('delay_field_empty', Messages::get('failures.delay_field_empty', ['field' => Read::string($config, 'until_field')]));
        }

        $offset = array_key_exists('offset', $config) ? Durations::parse($config['offset']) : null;

        return $offset === null ? Clock::utc($date) : Durations::add($offset, Clock::utc($date), $context->timezone(), $context->defaultCalendar(), $context->owner());
    }
}
