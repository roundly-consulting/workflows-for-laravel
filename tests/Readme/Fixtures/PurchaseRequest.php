<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Readme\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

/**
 * The README's host model: a plain Eloquent model of the host's own team (a `Company` here), with
 * a requester and an approver. No Roundly trait.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string $amount
 * @property int|null $requester_id
 * @property int|null $approver_id
 * @property-read User|null $requester
 */
class PurchaseRequest extends Model
{
    protected $table = 'readme_purchase_requests';

    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'team_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
