<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

use RoundlyConsulting\Workflows\Enums\FieldType;

/**
 * One declared input of a manual trigger: `Input::decimal('amount', 'Amount', required: true)`.
 */
final readonly class Input
{
    /**
     * @param  array<string, mixed>  $value
     */
    private function __construct(
        private array $value,
    ) {}

    /**
     * @param  list<string>  $rules  extra Laravel validation rules
     */
    public static function string(string $name, ?string $label = null, bool $required = false, array $rules = []): self
    {
        return self::of(FieldType::String, $name, $label, $required, [], $rules);
    }

    /**
     * @param  list<string>  $rules
     */
    public static function integer(string $name, ?string $label = null, bool $required = false, array $rules = []): self
    {
        return self::of(FieldType::Integer, $name, $label, $required, [], $rules);
    }

    /**
     * @param  list<string>  $rules
     */
    public static function decimal(string $name, ?string $label = null, bool $required = false, array $rules = []): self
    {
        return self::of(FieldType::Decimal, $name, $label, $required, [], $rules);
    }

    public static function boolean(string $name, ?string $label = null, bool $required = false): self
    {
        return self::of(FieldType::Boolean, $name, $label, $required);
    }

    /**
     * @param  list<string>  $rules
     */
    public static function date(string $name, ?string $label = null, bool $required = false, array $rules = []): self
    {
        return self::of(FieldType::Date, $name, $label, $required, [], $rules);
    }

    /**
     * One of fixed options (an `enum` input).
     *
     * @param  list<string|int>  $options
     */
    public static function choice(string $name, array $options, ?string $label = null, bool $required = false): self
    {
        return self::of(FieldType::Enum, $name, $label, $required, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->value;
    }

    /**
     * @param  list<string|int>  $options
     * @param  list<string>  $rules
     */
    private static function of(FieldType $type, string $name, ?string $label, bool $required, array $options = [], array $rules = []): self
    {
        $input = ['name' => $name, 'type' => $type->value];

        if ($label !== null) {
            $input['label'] = $label;
        }

        if ($required) {
            $input['required'] = true;
        }

        if ($options !== []) {
            $input['options'] = $options;
        }

        if ($rules !== []) {
            $input['rules'] = $rules;
        }

        return new self($input);
    }
}
