<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plain owner (no teams).
 *
 * @property int $id
 * @property string $name
 * @property string|null $plan
 */
class Company extends Model
{
    protected $guarded = [];
}
