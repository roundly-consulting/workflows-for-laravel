<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Bridges\Lifecycle;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioned;
use RoundlyConsulting\Lifecycle\Exceptions\DirectStateWriteException;
use RoundlyConsulting\Workflows\Enums\FailureCode;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Support\Engine\StepFailure;
use RoundlyConsulting\Workflows\Support\Messages;
use Throwable;

/**
 * lifecycle-for-laravel, when installed: the default transition adapter (the lifecycle guard
 * decides), a `set_field` on a lifecycle state column failing `field_write_refused`, and — opt-in
 * — lifecycle transitions emitted as `lifecycle.<lifecycle>.<transition>` events.
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
     * Listen for lifecycle state changes (they emit only while
     * `workflows.bridges.lifecycle.emit_transitions` is on).
     */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(LifecycleTransitioned::class, [EmitLifecycleTransition::class, 'handle']);
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
