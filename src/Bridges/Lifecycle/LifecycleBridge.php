<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Lifecycle;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\Engine\ExceptionMap;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\Messages;
use Throwable;

/**
 * lifecycle-for-laravel, when installed: the default transition adapter (the lifecycle guard
 * decides), and a `set_field` on a lifecycle state column failing `field_write_refused`.
 *
 * @internal
 */
final class LifecycleBridge
{
    public static function register(Application $app): void
    {
        if (! Bridges::available('lifecycle')) {
            return;
        }

        $map = $app->make(ExceptionMap::class);

        $map->register(static fn (Throwable $exception): ?StepFailure => $exception instanceof DirectStateWriteException
            ? StepFailure::permanent('field_write_refused', Messages::get('failures.field_write_refused'))
            : null);
    }

    /**
     * @return class-string|null
     */
    public static function defaultAdapter(): ?string
    {
        return Bridges::available('lifecycle') ? LifecycleTransitionAdapter::class : null;
    }
}
