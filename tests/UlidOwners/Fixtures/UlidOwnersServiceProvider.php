<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Workflows\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Workflows\Enums\FieldType;
use RoundlyConsulting\Workflows\Facades\Workflows;

/**
 * The ULID host app: tickets owned by tenants, each tenant with its own custom ticket fields;
 * a tenant in the EU also gets an owner field `region` (read by its path). Every policy allows.
 */
final class UlidOwnersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Workflows::subjects()->register(
            Workflows::subject('ticket', Ticket::class)
                ->label('Ticket')
                ->ownedBy('tenant')
                ->field('title', 'string', 'Title', writable: true)
                ->field('requester', 'party', 'Requester')
                ->dynamicFields(static fn (?Model $owner): array => $owner instanceof Tenant ? $owner->ticketFields() : []),
        );

        Workflows::subjects()->register(
            Workflows::subject('tenant', Tenant::class)
                ->label('Tenant')
                ->ownerType()
                ->dynamicFields(static fn (?Model $owner): array => $owner instanceof Tenant && $owner->region !== null
                    ? [new FieldDefinition('region', FieldType::String, 'Region')]
                    : []),
        );

        Gate::define('update', static fn (mixed $user = null, mixed $subject = null): bool => true);
    }
}
