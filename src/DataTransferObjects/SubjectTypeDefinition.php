<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * A registered subject (or owner) type: its registry key, label and fields. The model class
 * lives only in the in-memory registry and never goes over the wire (`toArray()` omits it).
 */
final readonly class SubjectTypeDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, FieldDefinition>  $fields
     */
    public function __construct(
        public string $key,
        public string $model,
        public string $label,
        public array $fields = [],
        public ?string $ownedBy = null,
        public bool $ownerType = false,
        public string $ability = 'update',
    ) {}

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
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
