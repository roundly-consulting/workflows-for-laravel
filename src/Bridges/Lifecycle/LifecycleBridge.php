<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Lifecycle;

use Closure;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Support\Bridges;
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
    /**
     * @return class-string|null
     */
    public static function defaultAdapter(): ?string
    {
        return Bridges::available('lifecycle') ? LifecycleTransitionAdapter::class : null;
    }

    /**
     * @return Closure(Throwable): ?StepFailure
     */
    public static function exceptions(): Closure
    {
        return static fn (Throwable $exception): ?StepFailure => $exception instanceof DirectStateWriteException
            ? StepFailure::permanent(FailureCode::FieldWriteRefused, Messages::get('failures.field_write_refused'))
            : null;
    }
}
