<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the host step creates; `workflow_key` is unique, so a retry can never duplicate one.
 *
 * @property int $id
 * @property string $title
 * @property string|null $workflow_key
 */
class Task extends Model
{
    protected $guarded = [];
}
