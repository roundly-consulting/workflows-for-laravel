<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * No definition with that key exists in the scope it was looked up in: the owner's (another
 * owner's key looks missing, and the message never names the other owner) or the app-wide one.
 */
final class UnknownDefinitionException extends WorkflowsException
{
    public static function key(string $key, ?Model $owner = null): self
    {
        return self::make('unknown_definition', 404, ['key' => $key], $owner === null ? 'unknown_definition_app_wide' : 'unknown_definition_owned');
    }

    /**
     * No webhook-triggered definition answers to that hook id.
     */
    public static function hook(string $hookId): self
    {
        return self::make('unknown_definition', 404, ['key' => $hookId]);
    }

    /**
     * No active webhook-triggered definition of the endpoint's owner names that inbound endpoint.
     */
    public static function endpoint(int|string $endpoint): self
    {
        return self::make('unknown_definition', 404, ['endpoint' => (string) $endpoint], 'unknown_definition_endpoint');
    }
}
