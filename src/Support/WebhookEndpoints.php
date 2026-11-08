<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Enums\DefinitionStatus;
use RoundlyConsulting\Workflows\Enums\TriggerType;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;

/**
 * Which active definitions a webhooks-package inbound endpoint starts: those whose current
 * version has a webhook trigger naming the endpoint (`trigger.endpoint`). The id is compared as a
 * string, so `5` and `"5"` are the same endpoint on every database engine.
 *
 * @internal
 */
final class WebhookEndpoints
{
    /**
     * The endpoint id a webhook trigger config names, or null.
     *
     * @param  array<array-key, mixed>  $trigger
     */
    public static function of(array $trigger): ?string
    {
        $endpoint = $trigger['endpoint'] ?? null;

        return (is_string($endpoint) && $endpoint !== '') || is_int($endpoint) ? (string) $endpoint : null;
    }

    /**
     * The owner's active definitions (app-wide ones for a null owner) whose webhook trigger names
     * the endpoint, oldest first — publish keeps it to one per owner.
     *
     * @return list<WorkflowDefinition>
     */
    public static function definitions(int|string $endpoint, ?Model $owner, ?string $except = null): array
    {
        return self::matching(Definitions::query($owner), $endpoint, $except);
    }

    /**
     * Whether any owner's active definition names the endpoint.
     */
    public static function namedAnywhere(int|string $endpoint): bool
    {
        $model = WorkflowModels::definition();

        return self::matching($model::query(), $endpoint, null, 1) !== [];
    }

    /**
     * @param  Builder<WorkflowDefinition>  $query
     * @return list<WorkflowDefinition>
     */
    private static function matching(Builder $query, int|string $endpoint, ?string $except, ?int $limit = null): array
    {
        $found = [];
        $query = $query
            ->where('status', DefinitionStatus::Active->value)
            ->when($except !== null, static fn (Builder $query): Builder => $query->where('key', '!=', $except))
            ->whereHas('currentVersion', static fn (Builder $version): Builder => $version->where('trigger_type', TriggerType::Webhook->value))
            ->with('currentVersion');

        foreach ($query->lazyById(100) as $definition) {
            $version = $definition->currentVersion;

            if ($version instanceof WorkflowVersion && self::of($version->trigger) === (string) $endpoint) {
                $found[] = $definition;

                if ($limit !== null && count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }
}
