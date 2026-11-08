<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property int|null $company_id
 * @property int|null $manager_id
 */
class User extends Authenticatable
{
    use SoftDeletes;

    protected $guarded = [];

    protected $table = 'users';

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }
}
