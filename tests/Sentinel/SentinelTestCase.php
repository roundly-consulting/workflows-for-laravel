<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Sentinel;

use RoundlyConsulting\Sentinel\SentinelServiceProvider;
use RoundlyConsulting\Workflows\Tests\Bridges\BridgesTestCase;

/**
 * The bridge app plus sentinel-for-laravel: the inbound-signature recipe.
 */
abstract class SentinelTestCase extends BridgesTestCase
{
    protected function packageProviders(): array
    {
        return [...parent::packageProviders(), SentinelServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [...parent::migrationSources(), SentinelServiceProvider::class];
    }
}
