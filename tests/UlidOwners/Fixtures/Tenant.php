<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;

/**
 * A ULID-keyed owner (a company) with its own custom ticket fields and a paused flag.
 *
 * @property string $id
 * @property string $name
 * @property string|null $region
 * @property bool $paused
 * @property list<array{name: string, type: string, label: string}>|null $custom_fields
 */
class Tenant extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * The tenant's custom ticket fields as workflow fields.
     *
     * @return list<FieldDefinition>
     */
    public function ticketFields(): array
    {
        return array_map(
            static fn (array $field): FieldDefinition => new FieldDefinition($field['name'], FieldType::from($field['type']), $field['label']),
            $this->custom_fields ?? [],
        );
    }

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'custom_fields' => 'array'];
    }
}
