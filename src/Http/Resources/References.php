<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * Renders a host model reference through the configured resource, in place (`resolve()`), so the
 * parent's `toArray()` stays a plain array. A model that is gone renders from its stored morph
 * pair: `{id, type: basename}` — no query, never a class name.
 *
 * @internal
 */
final class References
{
    /**
     * @return array<array-key, mixed>|null
     */
    public static function owner(?Model $model, ?string $type, int|string|null $id, Request $request): ?array
    {
        return self::render(WorkflowsConfig::ownerResource(), $model, $type, $id, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function subject(?Model $model, ?string $type, int|string|null $id, Request $request): ?array
    {
        return self::render(WorkflowsConfig::subjectResource(), $model, $type, $id, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function user(?Model $model, ?string $type, int|string|null $id, Request $request): ?array
    {
        return self::render(WorkflowsConfig::userResource(), $model, $type, $id, $request);
    }

    /**
     * @param  class-string<JsonResource>  $resource
     * @return array<array-key, mixed>|null
     */
    private static function render(string $resource, ?Model $model, ?string $type, int|string|null $id, Request $request): ?array
    {
        if ($model !== null) {
            return (new $resource($model))->resolve($request);
        }

        if ($type === null || $id === null) {
            return null;
        }

        return ['id' => $id, 'type' => class_basename(Relation::getMorphedModel($type) ?? $type)];
    }
}
