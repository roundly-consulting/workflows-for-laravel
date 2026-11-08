<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;

/**
 * The owner context for apps without tenancy: runs the callback as it is.
 */
final class NullOwnerContext implements RestoresOwnerContext
{
    public function run(?Model $owner, Closure $callback): mixed
    {
        return $callback();
    }
}
