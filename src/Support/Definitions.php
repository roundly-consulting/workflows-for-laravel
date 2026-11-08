<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Exceptions\UnknownDefinitionException;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;

/**
 * Owner-scoped definition lookups: a key only ever finds its owner's definition (or the
 * app-wide one for a null owner) — another owner's key looks missing.
 *
 * @internal
 */
final class Definitions
{
    /**
     * @throws UnknownDefinitionException
     */
    public static function find(string $key, ?Model $owner, bool $withTrashed = false): WorkflowDefinition
    {
        $definition = self::query($owner, $withTrashed)->where('key', $key)->first();

        return $definition instanceof WorkflowDefinition ? $definition : throw UnknownDefinitionException::key($key, $owner);
    }

    /**
     * The scope a subject's start looks in: its owner's definition, else the app-wide one (the
     * owner's wins on a key clash). Returns the owner, or null for app-wide.
     */
    public static function scopeFor(string $key, ?Model $owner): ?Model
    {
        if ($owner === null || self::exists($key, $owner)) {
            return $owner;
        }

        return self::exists($key, null) ? null : $owner;
    }

    public static function exists(string $key, ?Model $owner): bool
    {
        return self::query($owner)->where('key', $key)->exists();
    }

    /**
     * @return Builder<WorkflowDefinition>
     */
    public static function query(?Model $owner, bool $withTrashed = false): Builder
    {
        $model = WorkflowModels::definition();
        $query = $model::query()->where('owner_scope', Owners::scope($owner));

        return $withTrashed ? $query->withTrashed() : $query;
    }

    public static function owner(WorkflowDefinition $definition): ?Model
    {
        return Engine\Runtime::load($definition->owner_type, $definition->owner_id);
    }
}
