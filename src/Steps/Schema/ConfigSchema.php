<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Steps\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Writes a host step type's `configSchema()` with only the keywords the package's validator
 * supports, so a built schema always registers:
 *
 *     ConfigSchema::object()
 *         ->string('title', required: true, maxLength: 200)
 *         ->party('assignee', ownerSpecific: true)
 *         ->enum('priority', ['low', 'normal', 'high'], default: 'normal')
 *         ->toArray();
 *
 * The `$defs` helpers (`value`, `party`, `parties`, `duration`, `condition`, `field`) point at the
 * package's shared definitions; `nodeId()` marks a node reference, remapped on template import.
 * Immutable: every method returns a new schema.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ConfigSchema implements Arrayable
{
    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     */
    private function __construct(
        private bool $additional,
        private array $properties = [],
        private array $required = [],
    ) {}

    /**
     * An object schema; `$additional` allows properties beyond the declared ones.
     */
    public static function object(bool $additional = false): self
    {
        return new self($additional);
    }

    public function string(string $name, bool $required = false, ?int $minLength = null, ?int $maxLength = null, ?string $pattern = null, ?string $format = null, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, [
            'type' => 'string',
            'minLength' => $minLength,
            'maxLength' => $maxLength,
            'pattern' => $pattern,
            'format' => $format,
        ]);
    }

    public function integer(string $name, bool $required = false, ?int $min = null, ?int $max = null, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, ['type' => 'integer', 'minimum' => $min, 'maximum' => $max]);
    }

    public function number(string $name, bool $required = false, int|float|null $min = null, int|float|null $max = null, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, ['type' => 'number', 'minimum' => $min, 'maximum' => $max]);
    }

    public function boolean(string $name, bool $required = false, ?bool $default = null, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, ['type' => 'boolean', 'default' => $default]);
    }

    /**
     * @param  list<string|int>  $values
     */
    public function enum(string $name, array $values, bool $required = false, string|int|null $default = null, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, ['enum' => $values, 'default' => $default]);
    }

    /**
     * A list of a JSON type (`'string'`, `'integer'`, …) or of objects described by a schema.
     */
    public function list(string $name, self|string $items, bool $required = false, ?int $minItems = null, ?int $maxItems = null, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, [
            'type' => 'array',
            'items' => $items instanceof self ? $items->toArray() : ['type' => $items],
            'minItems' => $minItems,
            'maxItems' => $maxItems,
        ]);
    }

    /**
     * A nested object property (`object()` is the static entry point, so this one is `nested()`).
     */
    public function nested(string $name, self $schema, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, $schema->toArray());
    }

    /**
     * A literal, `{"ref": …}` or `{"relative": …}`.
     */
    public function value(string $name, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->ref($name, 'value', $required, $description, $label);
    }

    /**
     * One party; `$ownerSpecific` flags the value for review when a template moves to another owner.
     */
    public function party(string $name, bool $ownerSpecific = false, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->ref($name, 'party', $required, $description, $label, $ownerSpecific);
    }

    public function parties(string $name, bool $ownerSpecific = false, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->ref($name, 'parties', $required, $description, $label, $ownerSpecific);
    }

    public function duration(string $name, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->ref($name, 'duration', $required, $description, $label);
    }

    public function condition(string $name, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->ref($name, 'condition', $required, $description, $label);
    }

    /**
     * A registered field path (`subject.amount`).
     */
    public function field(string $name, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->ref($name, 'field', $required, $description, $label);
    }

    /**
     * Another node's id: remapped with the node when a template is imported.
     */
    public function nodeId(string $name, bool $required = false, ?string $description = null, ?string $label = null): self
    {
        return $this->with($name, $required, $label, $description, ['type' => 'string', 'format' => 'node-id']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = ['type' => 'object'];

        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }

        $schema['additionalProperties'] = $this->additional;
        $schema['properties'] = $this->properties;

        return $schema;
    }

    private function ref(string $name, string $definition, bool $required, ?string $description, ?string $label, bool $ownerSpecific = false): self
    {
        return $this->with($name, $required, $label, $description, [
            '$ref' => '#/$defs/'.$definition,
            'x-workflows-owner-specific' => $ownerSpecific ? true : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $keywords  null values are left out
     */
    private function with(string $name, bool $required, ?string $label, ?string $description, array $keywords): self
    {
        $property = array_filter([...$keywords, 'title' => $label, 'description' => $description], static fn (mixed $value): bool => $value !== null);

        return new self(
            $this->additional,
            [...$this->properties, $name => $property],
            $required && ! in_array($name, $this->required, true) ? [...$this->required, $name] : $this->required,
        );
    }
}
