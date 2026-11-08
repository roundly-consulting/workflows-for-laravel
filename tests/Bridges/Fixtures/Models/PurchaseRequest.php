<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Teams\Traits\BelongsToTeam;
use RoundlyConsulting\Teams\Traits\ScopedToTeam;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\PurchaseLifecycle;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Workflows\Traits\HasWorkflows;

/**
 * The D-1 subject: team-scoped, with a `purchase` lifecycle on `state`, a requester and an
 * approver whose manager escalations go to. Holds no workflow-specific code.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string|null $title
 * @property string|null $amount
 * @property string $state
 * @property int|null $requester_id
 * @property int|null $approver_id
 */
class PurchaseRequest extends Model implements LifecycleSubject
{
    use BelongsToTeam;
    use HasLifecycle;
    use HasWorkflows;
    use ScopedToTeam;
    use SoftDeletes;

    protected $guarded = [];

    public function lifecycleDefinitions(): array
    {
        return ['state' => PurchaseLifecycle::class];
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
