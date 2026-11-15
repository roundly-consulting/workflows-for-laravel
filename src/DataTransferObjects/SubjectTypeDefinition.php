<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;

/**
 * A registered subject (or owner) type: its registry key, label and fields. The model class
 * lives only in the in-memory registry and never goes over the wire (`toArray()` omits it).
 *
 * `dynamicFields` (from `SubjectTypeBuilder::dynamicFields()`) adds per-owner fields: `forOwner()`
 * resolves them for one owner into a definition holding both. Every validation, picker and run
 * scope reads the resolved definition, so two owners see their own fields.
 */
final readonly class SubjectTypeDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, FieldDefinition>  $fields
     * @param  (Closure(?Model): iterable<mixed>)|null  $dynamicFields
     */
    public function __construct(
        public string $key,
        public string $model,
        public string $label,
        public array $fields = [],
        public ?string $ownedBy = null,
        public bool $ownerType = false,
        public string $ability = 'update',
        public ?Closure $dynamicFields = null,
    ) {}

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    public function hasDynamicFields(): bool
    {
        return $this->dynamicFields !== null;
    }

    /**
     * This type with the owner's dynamic fields added (read-only, marked `dynamic`). A registered
     * field keeps its name: a dynamic field of the same name is dropped. The result carries no
     * resolver, so resolving it again is a no-op. A null owner is passed to the resolver as is
     * (app-wide definitions).
     *
     * @throws InvalidWorkflowsConfigurationException when the resolver returns anything but fields
     */
    public function forOwner(?Model $owner): self
    {
        if ($this->dynamicFields === null) {
            return $this;
        }

        $fields = $this->fields;

        foreach (($this->dynamicFields)($owner) as $field) {
            if (! $field instanceof FieldDefinition || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field->name) !== 1) {
                throw InvalidWorkflowsConfigurationException::invalidDynamicFields($this->key);
            }

            $fields[$field->name] ??= new FieldDefinition(
                name: $field->name,
                type: $field->type,
                label: $field->label,
                writable: false,
                options: $field->options,
                path: $field->path,
                dynamic: true,
            );
        }

        return new self($this->key, $this->model, $this->label, $fields, $this->ownedBy, $this->ownerType, $this->ability);
    }

    /**
     * @return array{key: string, label: string, owner_type: bool, fields: list<array{name: string, type: string, label: string, writable: bool, options: list<string|int>}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'owner_type' => $this->ownerType,
            'fields' => array_values(array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields)),
        ];
    }
}
