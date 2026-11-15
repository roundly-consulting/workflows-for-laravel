<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Builders;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\DataTransferObjects\SubjectTypeDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Exceptions\InvalidStepTypeException;
use RoundlyConsulting\Workflows\Exceptions\UnknownSubjectTypeException;

/**
 * Describes a subject type: `Workflows::subject('purchase_request', PurchaseRequest::class)
 * ->label('Purchase request')->ownedBy('team')->field('amount', 'decimal', 'Amount')`, then
 * `Workflows::subjects()->register($builder)`.
 */
final class SubjectTypeBuilder
{
    private string $label;

    /** @var array<string, FieldDefinition> */
    private array $fields = [];

    private ?string $ownedBy = null;

    private bool $ownerType = false;

    private string $ability = 'update';

    /** @var (Closure(?Model): iterable<mixed>)|null */
    private ?Closure $dynamicFields = null;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $key,
        private readonly string $model,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key) !== 1) {
            throw UnknownSubjectTypeException::key($key);
        }

        $this->label = $key;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The relation or attribute naming the subject's owner (a model).
     */
    public function ownedBy(string $relation): self
    {
        $this->ownedBy = $relation;

        return $this;
    }

    /**
     * Mark this type as an owner type, so `owner.<field>` refs validate against it.
     */
    public function ownerType(bool $ownerType = true): self
    {
        $this->ownerType = $ownerType;

        return $this;
    }

    /**
     * The policy ability `set_field` checks on this type (default `update`).
     */
    public function ability(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * @param  class-string<BackedEnum>|list<string|int>|null  $options
     */
    public function field(string $name, FieldType|string $type, ?string $label = null, string|array|null $options = null, ?string $path = null, bool $writable = false): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw InvalidStepTypeException::key($this->key.'.'.$name);
        }

        $type = $type instanceof FieldType ? $type : FieldType::from($type);

        $this->fields[$name] = new FieldDefinition(
            name: $name,
            type: $type,
            label: $label ?? $name,
            writable: $writable,
            options: self::options($options),
            path: $path,
        );

        return $this;
    }

    /**
     * Per-owner fields beside the registered ones (a company's custom fields): the resolver gets
     * the owner (null for an app-wide definition) and returns its FieldDefinitions. They count in
     * publish validation, the field pickers (`subjects()->export($key, $owner)`) and runs of that
     * owner; they are read through the model's `ResolvesWorkflowFields` when it implements it
     * (else by their path), and are never writable. Called once per validation or run scope.
     *
     * @param  Closure(?Model): iterable<FieldDefinition>  $resolver
     */
    public function dynamicFields(Closure $resolver): self
    {
        $this->dynamicFields = $resolver;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function build(): SubjectTypeDefinition
    {
        return new SubjectTypeDefinition(
            key: $this->key,
            model: $this->model,
            label: $this->label,
            fields: $this->fields,
            ownedBy: $this->ownedBy,
            ownerType: $this->ownerType,
            ability: $this->ability,
            dynamicFields: $this->dynamicFields,
        );
    }

    /**
     * @param  class-string<BackedEnum>|list<string|int>|null  $options
     * @return list<string|int>
     */
    private static function options(string|array|null $options): array
    {
        if ($options === null) {
            return [];
        }

        if (is_array($options)) {
            return $options;
        }

        if (! enum_exists($options) || ! is_subclass_of($options, BackedEnum::class)) {
            return [];
        }

        return array_map(static fn (BackedEnum $case): string|int => $case->value, $options::cases());
    }
}
