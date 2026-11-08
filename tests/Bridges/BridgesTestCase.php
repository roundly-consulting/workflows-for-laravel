<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Addresses\AddressesServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Attributes\AttributesServiceProvider;
use RoundlyConsulting\Connections\ConnectionsServiceProvider;
use RoundlyConsulting\Contacts\ContactsServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Forms\FormsServiceProvider;
use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\Notifications\NotificationsServiceProvider;
use RoundlyConsulting\OpeningHours\OpeningHoursServiceProvider;
use RoundlyConsulting\Options\Facades\Options;
use RoundlyConsulting\Options\OptionsServiceProvider;
use RoundlyConsulting\Teams\TeamsServiceProvider;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\BridgesServiceProvider;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\OfficeTeam;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types\DocumentNotice;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types\PurchaseApproved;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types\PurchaseEscalated;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types\PurchaseExpired;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types\PurchaseRejected;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types\UncheckedNotice;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\FixtureServiceProvider;
use RoundlyConsulting\Workflows\Tests\TestCase;
use RoundlyConsulting\Workflows\WorkflowsServiceProvider;

/**
 * The bridge test app: every optional package installed and booted (approvals, lifecycle,
 * notifications, teams, opening-hours, forms) next to the core fixtures. Group `bridges`: the
 * no-bridges CI leg leaves it out.
 */
abstract class BridgesTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Options::flushCache();
    }

    protected function bridges(): void
    {
        Bridges::reset();
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            OptionsServiceProvider::class,
            ContactsServiceProvider::class,
            AddressesServiceProvider::class,
            ApprovalsServiceProvider::class,
            ConnectionsServiceProvider::class,
            TeamsServiceProvider::class,
            LifecycleServiceProvider::class,
            NotificationsServiceProvider::class,
            OpeningHoursServiceProvider::class,
            MediaLibraryServiceProvider::class,
            AttributesServiceProvider::class,
            FormsServiceProvider::class,
            WorkflowsServiceProvider::class,
            FixtureServiceProvider::class,
            BridgesServiceProvider::class,
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            OptionsServiceProvider::class,
            ContactsServiceProvider::class,
            AddressesServiceProvider::class,
            ConnectionsServiceProvider::class,
            ApprovalsServiceProvider::class,
            TeamsServiceProvider::class,
            LifecycleServiceProvider::class,
            NotificationsServiceProvider::class,
            OpeningHoursServiceProvider::class,
            MediaLibraryServiceProvider::class,
            AttributesServiceProvider::class,
            FormsServiceProvider::class,
            WorkflowsServiceProvider::class,
            __DIR__.'/../Fixtures/migrations',
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'options.cache.enabled' => false,
            'connections.cache.enabled' => false,
            'notifications.queue.enabled' => 'off',
            'notifications.types' => [
                PurchaseEscalated::class, PurchaseApproved::class, PurchaseRejected::class, PurchaseExpired::class,
                DocumentNotice::class, UncheckedNotice::class,
            ],
            'media.disk' => 'local',
            'teams.models.team' => OfficeTeam::class,
        ];
    }
}
