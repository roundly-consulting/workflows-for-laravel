<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Every package model's "now" is UTC: Eloquent's timestamps and soft deletes. (A local copy of
 * notifications' concern; a toolkit extraction is an upstream ask.)
 */
trait StoresUtcTimestamps
{
    public function freshTimestamp(): CarbonInterface
    {
        return Date::now('UTC')->startOfSecond();
    }
}
