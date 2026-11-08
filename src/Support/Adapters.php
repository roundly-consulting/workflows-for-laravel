<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\Workflows\Bridges\Lifecycle\LifecycleBridge;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamsBridge;
use RoundlyConsulting\Workflows\Contracts\DeliversWebhooks;
use RoundlyConsulting\Workflows\Contracts\ResolvesRoles;
use RoundlyConsulting\Workflows\Contracts\TransitionAdapter;

/**
 * The optional contracts, resolved when used: a container binding the host made, else the
 * class its config key names, else the bridge default when that package is installed, else
 * none (the feature is unavailable, and publish validation says so).
 *
 * @internal
 */
final class Adapters
{
    public static function transition(): ?TransitionAdapter
    {
        $adapter = self::resolve(TransitionAdapter::class, WorkflowsConfig::transitionAdapter(), LifecycleBridge::defaultAdapter());

        return $adapter instanceof TransitionAdapter ? $adapter : null;
    }

    public static function roles(): ?ResolvesRoles
    {
        $resolver = self::resolve(ResolvesRoles::class, WorkflowsConfig::partiesRoles(), TeamsBridge::defaultRoles());

        return $resolver instanceof ResolvesRoles ? $resolver : null;
    }

    public static function webhooks(): ?DeliversWebhooks
    {
        $deliverer = self::resolve(DeliversWebhooks::class, WorkflowsConfig::webhooksAdapter(), null);

        return $deliverer instanceof DeliversWebhooks ? $deliverer : null;
    }

    /**
     * @param  class-string  $contract
     * @param  class-string|null  $configured  already checked against the contract
     */
    private static function resolve(string $contract, ?string $configured, ?string $default): ?object
    {
        $class = app()->bound($contract) ? $contract : ($configured ?? $default);
        $resolved = $class === null ? null : app($class);

        return is_object($resolved) ? $resolved : null;
    }
}
