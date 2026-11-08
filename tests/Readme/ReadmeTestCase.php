<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Readme;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\TestCase;

/**
 * A fresh host that followed the README's Installation and nothing else: the default `database`
 * queue with the workers the README implies, and only the Roundly packages its install block
 * requires — every other bridge is missing, whatever this repository has in vendor.
 */
abstract class ReadmeTestCase extends TestCase
{
    public static function readme(): string
    {
        return (string) file_get_contents(__DIR__.'/../../README.md');
    }

    /**
     * The README's `## Installation` section (up to `## Usage`).
     */
    public static function installation(): string
    {
        preg_match('/## Installation\n(.*?)\n## Usage/s', self::readme(), $match);

        return $match[1] ?? '';
    }

    /**
     * The bridges (by their `Bridges::MARKERS` name) the Installation's `composer require` lines install.
     *
     * @return list<string>
     */
    public static function installedBridges(): array
    {
        preg_match_all('/^composer require (.+)$/m', self::installation(), $lines);
        preg_match_all('#roundly-consulting/([a-z-]+)-for-laravel#', implode(' ', $lines[1]), $packages);

        return array_values(array_intersect(array_keys(Bridges::MARKERS), $packages[1]));
    }

    /**
     * The `retry_after` the Installation tells the host to set (`DB_QUEUE_RETRY_AFTER=…`), if any.
     */
    public static function retryAfter(): ?int
    {
        return preg_match('/DB_QUEUE_RETRY_AFTER=(\d+)/', self::installation(), $match) === 1 ? (int) $match[1] : null;
    }

    protected function bridges(): void
    {
        Bridges::reset();
        Bridges::pretendMissing(array_values(array_diff(array_keys(Bridges::MARKERS), self::installedBridges())));
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [
            ...(in_array('approvals', self::installedBridges(), true) ? [ApprovalsServiceProvider::class] : []),
            ...parent::packageProviders(),
        ];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [
            ...(in_array('approvals', self::installedBridges(), true) ? [ApprovalsServiceProvider::class] : []),
            ...parent::migrationSources(),
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            // Laravel 13's defaults: QUEUE_CONNECTION=database and the package's queue on.
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => self::retryAfter() ?? 90,
            'workflows.queue.enabled' => 'on',
        ];
    }
}
