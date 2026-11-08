<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidEventKeyException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Support\Conditions\ConditionValidator;
use RoundlyConsulting\Workflows\Support\Schedule\CronExpression;
use RoundlyConsulting\Workflows\Support\Schedule\InvalidCronException;
use RoundlyConsulting\Workflows\Support\Schedule\Presets;
use RoundlyConsulting\Workflows\Support\TriggerForms;
use RoundlyConsulting\Workflows\Support\TriggerInputs;

/**
 * The one trigger node of a graph: manual (with optional input fields or a form), an app event,
 * a schedule, or an inbound webhook. Completed at start, its output is the trigger payload.
 *
 * @internal
 */
final class TriggerStep extends BuiltinStep
{
    public const string EVENT_KEY = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    /**
     * The one check of an emitted event key (internal `@` keys refused): the manager runs it before
     * the fake records an emit, and the emit action runs it too, so the two never disagree.
     *
     * @throws InvalidEventKeyException
     */
    public static function assertEventKey(string $event): void
    {
        if (str_starts_with($event, '@') || preg_match(self::EVENT_KEY, $event) !== 1) {
            throw InvalidEventKeyException::key($event);
        }
    }

    public function key(): string
    {
        return 'trigger';
    }

    public function category(): string
    {
        return 'trigger';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function transactional(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['trigger'],
            'additionalProperties' => false,
            'properties' => [
                'trigger' => ['enum' => ['manual', 'event', 'schedule', 'webhook']],
                'inputs' => [
                    'type' => 'array',
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'type'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                            'type' => ['enum' => FieldType::values()->all()],
                            'label' => ['type' => 'string', 'maxLength' => 200],
                            'required' => ['type' => 'boolean'],
                            'options' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
                            'rules' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 100]],
                        ],
                    ],
                ],
                'form' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'event' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'condition' => ['$ref' => '#/$defs/condition'],
                'cron' => ['type' => 'string', 'format' => 'cron'],
                'preset' => [
                    'type' => 'object',
                    'required' => ['every'],
                    'additionalProperties' => false,
                    'properties' => [
                        'every' => ['enum' => ['day', 'weekday', 'week', 'month', 'hour', 'minutes']],
                        'at' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'],
                        'day' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 31],
                        'interval' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 59],
                    ],
                ],
                'timezone' => ['type' => 'string', 'format' => 'timezone'],
                'for_each' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'where' => ['$ref' => '#/$defs/condition'],
                        'limit' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
                'idempotency' => [
                    'type' => 'object',
                    'required' => ['from'],
                    'additionalProperties' => false,
                    'properties' => ['from' => ['type' => 'string', 'pattern' => '^(header:[A-Za-z0-9-]{1,100}|payload:[A-Za-z0-9_.]{1,200})$']],
                ],
                'subject' => [
                    'type' => 'object',
                    'required' => ['lookup', 'value'],
                    'additionalProperties' => false,
                    'properties' => [
                        'lookup' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                        'value' => ['$ref' => '#/$defs/value'],
                    ],
                ],
                'endpoint' => ['type' => ['string', 'integer', 'null'], 'x-workflows-owner-specific' => true],
            ],
        ];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $errors = [];
        $node = $scope->node?->id;
        $trigger = $config['trigger'] ?? null;

        $required = match ($trigger) {
            'event' => ['event'],
            'webhook' => ['idempotency'],
            default => [],
        };

        foreach ($required as $key) {
            if (! array_key_exists($key, $config)) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/'.$key, params: ['keyword' => 'required', 'expected' => $key]);
            }
        }

        if ($trigger === 'event' && is_string($config['event'] ?? null) && preg_match(self::EVENT_KEY, $config['event']) !== 1) {
            $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/event', params: ['keyword' => 'pattern', 'expected' => 'a dotted event key']);
        }

        if ($trigger === 'schedule') {
            $errors = [...$errors, ...$this->schedule($config, $node)];
        }

        if (array_key_exists('for_each', $config)) {
            $errors = [...$errors, ...$this->forEach($config, $scope, $node)];
        }

        if (is_string($config['form'] ?? null)) {
            $forms = app(TriggerForms::class);

            if (! $forms->available()) {
                $errors[] = ValidationError::make(ValidationCode::AdapterMissing, node: $node, path: '/form', params: ['type' => 'form', 'package' => 'roundly-consulting/forms-for-laravel']);
            } elseif (! $forms->exists($config['form'])) {
                $errors[] = ValidationError::make(ValidationCode::UnknownForm, node: $node, path: '/form', params: ['form' => $config['form']]);
            }
        }

        $names = [];

        foreach (is_array($config['inputs'] ?? null) ? $config['inputs'] : [] as $index => $input) {
            $name = is_array($input) ? ($input['name'] ?? null) : null;

            if (is_string($name) && isset($names[$name])) {
                $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/inputs/'.$index.'/name', params: ['keyword' => 'uniqueItems', 'expected' => 'unique input names']);
            }

            if (is_string($name)) {
                $names[$name] = true;
            }

            foreach (is_array($input) && is_array($input['rules'] ?? null) ? $input['rules'] : [] as $ruleIndex => $rule) {
                if (! is_string($rule) || ! TriggerInputs::ruleAllowed($rule)) {
                    $errors[] = ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/inputs/'.$index.'/rules/'.$ruleIndex, params: ['keyword' => 'rules', 'expected' => 'max:, min:, email or url']);
                }
            }
        }

        if (array_key_exists('condition', $config)) {
            $errors = [...$errors, ...ConditionValidator::validate($config['condition'], $scope, '/condition')];
        }

        return $errors;
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::complete(output: $context->trigger());
    }

    /**
     * The cron expression of a schedule trigger (a preset compiled), or null.
     *
     * @param  array<string, mixed>  $config
     */
    public static function cronOf(array $config): ?CronExpression
    {
        try {
            if (is_string($config['cron'] ?? null)) {
                return CronExpression::parse($config['cron']);
            }

            if (is_array($config['preset'] ?? null)) {
                return CronExpression::parse(Presets::compile($config['preset']));
            }
        } catch (InvalidCronException) {
            return null;
        }

        return null;
    }

    /**
     * `for_each` runs a schedule once per subject of the definition's subject type.
     *
     * @param  array<string, mixed>  $config
     * @return list<ValidationError>
     */
    private function forEach(array $config, ValidationScope $scope, ?string $node): array
    {
        if (($config['trigger'] ?? null) !== 'schedule' || $scope->subjectType === null) {
            return [ValidationError::make(ValidationCode::InvalidConfig, node: $node, path: '/for_each', params: ['keyword' => 'for_each', 'expected' => 'a schedule trigger of a definition with a subject type'])];
        }

        $where = is_array($config['for_each']) ? ($config['for_each']['where'] ?? null) : null;

        return $where === null ? [] : ConditionValidator::validate($where, $scope, '/for_each/where');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<ValidationError>
     */
    private function schedule(array $config, ?string $node): array
    {
        $hasCron = array_key_exists('cron', $config);
        $hasPreset = array_key_exists('preset', $config);

        if ($hasCron === $hasPreset) {
            return [ValidationError::make(ValidationCode::InvalidCron, node: $node, path: '/cron', params: ['token' => 'cron|preset'])];
        }

        try {
            $hasCron && is_string($config['cron'])
                ? CronExpression::parse($config['cron'])
                : CronExpression::parse(Presets::compile(is_array($config['preset']) ? $config['preset'] : []));
        } catch (InvalidCronException $exception) {
            return [ValidationError::make(ValidationCode::InvalidCron, node: $node, path: $hasCron ? '/cron' : '/preset', params: ['token' => $exception->token])];
        }

        return [];
    }
}
