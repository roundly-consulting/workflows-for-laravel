<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Actions\Instances;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\DataTransferObjects\StartData;
use RoundlyConsulting\Workflows\DataTransferObjects\StartResult;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Exceptions\MissingIdempotencyKeyException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Support\Definitions;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * The inbound webhook start call: the hook id (a definition's `webhook_id`) addresses the
 * definition, the trigger config says where the idempotency key comes from (required) and how
 * the subject is looked up. Signature checks belong to the route middleware (or the host).
 */
final readonly class ReceiveWebhookAction
{
    public function __construct(
        private StartInstanceAction $start,
        private SubjectRegistry $subjects,
        private RestoresOwnerContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function execute(string $hookId, array $payload, array $headers = []): StartResult
    {
        $model = WorkflowModels::definition();
        $definition = $model::query()->where('webhook_id', $hookId)->first();
        $version = $definition instanceof WorkflowDefinition ? $definition->currentVersion : null;

        if (! $definition instanceof WorkflowDefinition || ! $version instanceof WorkflowVersion || $version->trigger_type !== TriggerType::Webhook) {
            throw UnknownDefinitionException::hook($hookId);
        }

        $key = $this->idempotencyKey($version->trigger, $payload, $headers);

        if ($key === null) {
            throw MissingIdempotencyKeyException::webhook();
        }

        $owner = Definitions::owner($definition);
        $subject = $this->context->run($owner, fn (): ?Model => $this->subject($definition, $version->trigger, $payload));

        return $this->start->execute(new StartData(
            key: $definition->key,
            subject: $subject,
            owner: $owner,
            idempotencyKey: 'webhook:'.$key,
            trigger: TriggerType::Webhook,
            payload: ['payload' => $payload],
        ));
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    private function idempotencyKey(array $trigger, array $payload, array $headers): ?string
    {
        $from = is_array($trigger['idempotency'] ?? null) ? ($trigger['idempotency']['from'] ?? null) : null;

        if (! is_string($from)) {
            return null;
        }

        if (str_starts_with($from, 'header:')) {
            $name = strtolower(substr($from, 7));
            $value = null;

            foreach ($headers as $header => $values) {
                if (strtolower((string) $header) === $name) {
                    $value = is_array($values) ? ($values[0] ?? null) : $values;
                }
            }
        } else {
            $value = Arr::get($payload, substr($from, 8));
        }

        return (is_string($value) && $value !== '') || is_int($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $payload
     */
    private function subject(WorkflowDefinition $definition, array $trigger, array $payload): ?Model
    {
        $lookup = is_array($trigger['subject'] ?? null) ? $trigger['subject'] : null;

        if ($lookup === null || $definition->subject_key === null) {
            return null;
        }

        $type = $this->subjects->get($definition->subject_key);
        $field = is_string($lookup['lookup'] ?? null) ? $type->field($lookup['lookup']) : null;
        $ref = is_array($lookup['value'] ?? null) ? ($lookup['value']['ref'] ?? null) : null;
        $value = is_string($ref) && str_starts_with($ref, 'payload.') ? Arr::get($payload, substr($ref, 8)) : null;

        if ($field === null || $field->isRelationPath() || ! is_scalar($value)) {
            throw SubjectOwnerMismatchException::forSubject();
        }

        $model = $type->model;
        $subject = $model::query()->where($field->path(), $value)->first();

        return $subject instanceof Model ? $subject : throw SubjectOwnerMismatchException::forSubject();
    }
}
