<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\FieldType;

/**
 * One registered field of a subject type: what conditions, pickers and set_field may use.
 * `path` (attribute or dotted relation) is how it is read; only registered fields are ever read.
 */
final readonly class FieldDefinition
{
    /**
     * @param  list<string|int>  $options
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public string $label,
        public bool $writable = false,
        public array $options = [],
        public ?string $path = null,
    ) {}

    public function path(): string
    {
        return $this->path ?? $this->name;
    }

    public function isRelationPath(): bool
    {
        return str_contains($this->path(), '.');
    }

    /**
     * @return array{name: string, type: string, label: string, writable: bool, options: list<string|int>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'label' => $this->label,
            'writable' => $this->writable,
            'options' => $this->options,
        ];
    }
}
