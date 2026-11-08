<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;

/**
 * A webhook trigger's subject lookup (`subject: {lookup, value: {ref: "payload.<path>"}}`), shared
 * by the hook-id start and the webhooks-package delivery start.
 *
 * @internal
 */
final readonly class WebhookSubjects
{
    public function __construct(
        private SubjectRegistry $subjects,
        private Owners $owners,
    ) {}

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<array-key, mixed>  $payload
     *
     * @throws SubjectOwnerMismatchException
     */
    public function find(WorkflowDefinition $definition, array $trigger, array $payload): ?Model
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
        $owner = Definitions::owner($definition);

        // The owner's own record: another owner may hold the same value (an invoice number is
        // unique per tenant, not across them), and without a global owner scope it may come first.
        foreach ($model::query()->where($field->path(), $value)->lazyById(100) as $candidate) {
            if ($owner === null || $this->owners->matches($owner, $candidate)) {
                return $candidate;
            }
        }

        throw SubjectOwnerMismatchException::forSubject();
    }
}
