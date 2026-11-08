<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Testing\InteractsWithWorkflows;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\FixtureServiceProvider;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\CreateTaskStep;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\FlakyState;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\ReviewStep;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\SpyStep;
use RoundlyConsulting\Workflows\WorkflowsServiceProvider;

/**
 * The core test app: workflows alone (no bridge package booted), a Company-owned Document
 * subject and host step types. Steps run inline (`queue.enabled = off`) unless a test turns the
 * queue on.
 */
abstract class TestCase extends PackageTestCase
{
    use InteractsWithWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        SpyStep::reset();
        CreateTaskStep::$calls = 0;
        FlakyState::$failing = true;
        FixtureServiceProvider::$allow = true;
    }

    protected function tearDown(): void
    {
        Bridges::reset();

        parent::tearDown();
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            WorkflowsServiceProvider::class,
            FixtureServiceProvider::class,
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            WorkflowsServiceProvider::class,
            __DIR__.'/Fixtures/migrations',
        ];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.timezone' => 'UTC',
            'auth.providers.users.model' => User::class,
            'queue.default' => 'sync',
            'workflows.queue.enabled' => 'off',
            'workflows.steps' => [SpyStep::class, ReviewStep::class],
        ];
    }
}
