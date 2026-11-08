<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Webhooks;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\SafeFetch\SafeFetchServiceProvider;
use RoundlyConsulting\Sentinel\SentinelServiceProvider;
use RoundlyConsulting\Webhooks\WebhooksServiceProvider;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\FixtureServiceProvider;
use RoundlyConsulting\Workflows\Tests\TestCase;
use RoundlyConsulting\Workflows\WorkflowsServiceProvider;

/**
 * The core app plus webhooks-for-laravel (and what it requires): the inbound `workflows` handler
 * and the outbound `webhook` step. Every other bridge stays missing. Group `bridges`.
 */
abstract class WebhooksTestCase extends TestCase
{
    protected function bridges(): void
    {
        Bridges::reset();
        Bridges::pretendMissing(array_values(array_diff(array_keys(Bridges::MARKERS), ['webhooks'])));
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            SentinelServiceProvider::class,
            HttpClientRateLimitsServiceProvider::class,
            SafeFetchServiceProvider::class,
            WebhooksServiceProvider::class,
            WorkflowsServiceProvider::class,
            FixtureServiceProvider::class,
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
