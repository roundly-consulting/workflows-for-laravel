<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Runs a callback inside an owner's tenant context (teams: `Teams::runAs()`), restoring the
 * previous context afterwards, also on an exception. Wraps every queued step, start, emit,
 * retry, cancel and dry run.
 */
interface RestoresOwnerContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?Model $owner, Closure $callback): mixed;
}
