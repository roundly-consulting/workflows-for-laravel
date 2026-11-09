<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\WebhooksTeams;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\SafeFetch\SafeFetchServiceProvider;
use RoundlyConsulting\Sentinel\SentinelServiceProvider;
use RoundlyConsulting\Webhooks\WebhooksServiceProvider;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\Bridges\BridgesTestCase;

/**
 * The bridge test app (teams, its team-scoped purchase request) plus webhooks-for-laravel: the
 * outbound `webhook` step of an instance a team owns. Group `bridges`.
 */
abstract class WebhooksTeamsTestCase extends BridgesTestCase
{
    protected function bridges(): void
    {
        Bridges::reset();
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            SentinelServiceProvider::class,
            HttpClientRateLimitsServiceProvider::class,
            SafeFetchServiceProvider::class,
            WebhooksServiceProvider::class,
            ...parent::packageProviders(),
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            SentinelServiceProvider::class,
            WebhooksServiceProvider::class,
            ...parent::migrationSources(),
        ];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'app.url' => 'https://app.test',
        ];
    }
}
