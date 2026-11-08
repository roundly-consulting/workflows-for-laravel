<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Publish;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use RoundlyConsulting\Workflows\Tests\TestCase;

/**
 * The suite's base case with `config/` and `database/` pointed at a throwaway directory per test,
 * set before the providers boot (their publish destinations are fixed then) — never the shared
 * Testbench skeleton, which a parallel suite boots from.
 */
abstract class PublishSandboxTestCase extends TestCase
{
    private string $sandbox = '';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sandbox = sys_get_temp_dir().'/workflows-publish-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/config');
        File::ensureDirectoryExists($this->sandbox.'/database/migrations');

        $app->useConfigPath($this->sandbox.'/config');
        $app->useDatabasePath($this->sandbox.'/database');
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }
}
