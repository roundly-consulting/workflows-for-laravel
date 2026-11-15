<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\UlidOwners;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Workflows\Tests\TestCase;
use RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures\TenantState;
use RoundlyConsulting\Workflows\Tests\UlidOwners\Fixtures\UlidOwnersServiceProvider;
use RoundlyConsulting\Workflows\WorkflowsServiceProvider;

/**
 * The ULID-keyed host: tenants (owners) and tickets (subjects) with ULID keys, per-tenant custom
 * fields, and an OwnerState that pauses a suspended tenant. Users stay bigint. No bridge package.
 */
abstract class UlidOwnersTestCase extends TestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            WorkflowsServiceProvider::class,
            UlidOwnersServiceProvider::class,
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            ...parent::migrationSources(),
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'workflows.owner_key_type' => 'ulid',
            'workflows.subject_key_type' => 'ulid',
            'workflows.owners.state' => TenantState::class,
        ];
    }
}
