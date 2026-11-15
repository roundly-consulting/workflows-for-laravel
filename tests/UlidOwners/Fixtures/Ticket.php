<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Workflows\Contracts\ResolvesWorkflowFields;
use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

/**
 * A ULID-keyed subject owned by a Tenant, holding the tenant's custom field values in a JSON
 * column it reads itself.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $title
 * @property int|null $requester_id
 * @property array<string, mixed>|null $custom
 */
class Ticket extends Model implements ResolvesWorkflowFields
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function resolveWorkflowField(FieldDefinition $field): mixed
    {
        return $this->custom[$field->name] ?? null;
    }

    protected function casts(): array
    {
        return ['custom' => 'array'];
    }
}
