<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Workflows\Contracts\HasWorkflowOwner;
use RoundlyConsulting\Workflows\Traits\HasWorkflows;

/**
 * The core test subject, owned by a Company through HasWorkflowOwner.
 *
 * @property int $id
 * @property int|null $company_id
 * @property string|null $title
 * @property string|null $amount
 * @property string|null $priority
 * @property string|null $department
 * @property string|null $secret
 * @property bool|null $urgent
 * @property CarbonInterface|null $due_on
 * @property int|null $requester_id
 * @property int|null $approver_id
 */
class Document extends Model implements HasWorkflowOwner
{
    use HasWorkflows;
    use SoftDeletes;

    protected $guarded = [];

    public function workflowOwner(): ?Model
    {
        return $this->company_id === null ? null : Company::query()->find($this->company_id);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    protected function casts(): array
    {
        return ['due_on' => 'datetime', 'urgent' => 'boolean'];
    }
}
