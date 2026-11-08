<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Builtin;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Actions\Instances\StartInstanceAction;
use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\Severity;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\ValidationScope;
use RoundlyConsulting\Workflows\Steps\WaitSpec;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\Messages;
use RoundlyConsulting\Workflows\Support\Owners;
use RoundlyConsulting\Workflows\Support\Read;
use RoundlyConsulting\Workflows\Support\Values;
use RoundlyConsulting\Workflows\Support\WorkflowModels;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * `{definition, subject: "same" | {field: ref} | null, input?, wait}`: starts another workflow of
 * the same owner, one level deeper (nesting is capped at `limits.max_depth`). Idempotent through
 * the start key `child:{visit key}`, so a retry never starts a second child. With `wait: true` the
 * step waits for the child to end and takes its outcome (`completed`, `failed`, `cancelled`).
 *
 * @internal
 */
final class StartWorkflowStep extends BuiltinStep
{
    public function key(): string
    {
        return 'start_workflow';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['definition'],
            'additionalProperties' => false,
            'properties' => [
                'definition' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'subject' => [
                    'anyOf' => [
                        ['const' => 'same'],
                        ['type' => 'null'],
                        ['type' => 'object', 'required' => ['field'], 'additionalProperties' => false, 'properties' => ['field' => ['$ref' => '#/$defs/field']]],
                    ],
                ],
                'input' => ['type' => 'object'],
                'wait' => ['type' => 'boolean'],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        if (($config['wait'] ?? false) !== true) {
            return [Outcome::of('started')];
        }

        return [Outcome::of('completed'), Outcome::of('failed'), Outcome::of('cancelled', required: false)];
    }

    public function validateConfig(array $config, ValidationScope $scope): array
    {
        $node = $scope->node?->id;
        $key = $config['definition'] ?? null;

        if (! is_string($key)) {
            return [];
        }

        $definitionModel = WorkflowModels::definition();
        $definition = Definitions::query($scope->owner)->where('key', $key)->first();

        if (! $definition instanceof $definitionModel) {
            return [ValidationError::make(ValidationCode::UnknownDefinition, node: $node, path: '/definition', params: ['definition' => $key])];
        }

        $errors = [];

        if ($key === $scope->definitionKey && ($config['wait'] ?? false) !== false) {
            $errors[] = ValidationError::make(ValidationCode::SelfStart, node: $node, path: '/definition', params: ['definition' => $key], severity: Severity::Warning);
        }

        $version = $definition->currentVersion;

        if ($version instanceof WorkflowVersion) {
            if ($version->trigger_type !== TriggerType::Manual) {
                $errors[] = ValidationError::make(ValidationCode::ChildTriggerNotManual, node: $node, path: '/definition', params: ['definition' => $key]);
            }

            $mapped = is_array($config['input'] ?? null) ? $config['input'] : [];

            foreach (is_array($version->trigger['inputs'] ?? null) ? $version->trigger['inputs'] : [] as $input) {
                if (is_array($input) && ($input['required'] ?? false) === true && is_string($input['name'] ?? null) && ! array_key_exists($input['name'], $mapped)) {
                    $errors[] = ValidationError::make(ValidationCode::MissingChildInput, node: $node, path: '/input', params: ['input' => $input['name']]);
                }
            }
        }

        return $errors;
    }

    public function handle(StepContext $context): StepResult
    {
        $config = $context->config();
        $instance = $context->instance();

        if ($instance->depth + 1 > WorkflowsConfig::maxDepth()) {
            throw StepFailedException::because(FailureCode::NestingDepthExceeded, Messages::get('failures.nesting_depth_exceeded', ['limit' => WorkflowsConfig::maxDepth()]));
        }

        $child = $this->start($context, $config);
        $wait = ($config['wait'] ?? false) === true;
        $output = ['instance' => $child->ulid];

        if (! $wait) {
            return StepResult::complete('started', $output);
        }

        if ($child->status->isTerminal()) {
            return StepResult::complete($child->status->value, $output);
        }

        return StepResult::wait(WaitSpec::event('@instance.'.strtolower($child->ulid).'.ended')->probe(), $output);
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        $model = WorkflowModels::instance();
        $ulid = $context->stepRun()->output['instance'] ?? null;
        $child = is_string($ulid) ? $model::query()->where('ulid', $ulid)->first() : null;

        if (! $child instanceof WorkflowInstance) {
            return StepResult::complete('failed');
        }

        if (! $child->status->isTerminal()) {
            return StepResult::wait(WaitSpec::event('@instance.'.strtolower($child->ulid).'.ended'));
        }

        return StepResult::complete(match ($child->status) {
            InstanceStatus::Completed => 'completed',
            InstanceStatus::Cancelled => 'cancelled',
            default => 'failed',
        }, ['instance' => $child->ulid]);
    }

    public function simulate(StepContext $context): StepResult
    {
        $definition = Read::string($context->config(), 'definition');
        $wait = ($context->config()['wait'] ?? false) === true;

        return StepResult::complete($wait ? 'completed' : 'started')->wouldDo('start workflow '.$definition, ['definition' => $definition]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function start(StepContext $context, array $config): WorkflowInstance
    {
        $owner = $this->parentOwner($context);
        $key = Read::string($config, 'definition');
        $childModel = WorkflowModels::definition();
        $child = Definitions::query($owner)->where('key', $key)->first();

        // A subject-less child takes no subject, whatever `same` would hand it.
        $subject = $child instanceof $childModel && $child->subject_key === null ? null : $this->childSubject($context, $config['subject'] ?? 'same');

        if ($subject !== null && $context->owner() !== null && ! app(Owners::class)->matches($context->owner(), $subject)) {
            throw StepFailedException::because(FailureCode::OwnerMismatch, Messages::get('failures.owner_mismatch'));
        }

        try {
            $result = app(StartInstanceAction::class)->execute(new StartData(
                key: Read::string($config, 'definition'),
                subject: $subject,
                owner: $owner,
                input: Values::resolveMap(is_array($config['input'] ?? null) ? $config['input'] : [], $context->scope()),
                by: $context->actor()->user,
                idempotencyKey: 'child:'.$context->idempotencyKey(),
                trigger: TriggerType::Manual,
                parent: $context->stepRun(),
                internalKey: true,
            ));
        } catch (WorkflowsException $exception) {
            throw StepFailedException::because($exception->errorCode(), $exception->getMessage());
        }

        return $result->instance ?? throw StepFailedException::because(FailureCode::ChildNotStarted, Messages::get('failures.child_not_started'));
    }

    private function parentOwner(StepContext $context): ?Model
    {
        $definition = $context->instance()->definition;

        return $definition === null ? null : Definitions::owner($definition);
    }

    private function childSubject(StepContext $context, mixed $subject): ?Model
    {
        if ($subject === 'same') {
            return $context->subject();
        }

        if (is_array($subject) && is_string($subject['field'] ?? null)) {
            $value = $context->field($subject['field']);

            return $value instanceof Model ? $value : null;
        }

        return null;
    }
}
