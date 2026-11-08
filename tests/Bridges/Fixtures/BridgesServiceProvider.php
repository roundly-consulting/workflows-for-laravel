<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;

/**
 * The bridge test app: the purchase request subject (owner resolved by teams' team column), the
 * team owner type and the team roles. No workflow-specific PHP.
 */
final class BridgesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Workflows::subjects()->register(
            Workflows::subject('purchase_request', PurchaseRequest::class)
                ->label('Purchase request')
                ->field('amount', 'decimal', 'Amount')
                ->field('title', 'string', 'Title', writable: true)
                ->field('state', 'string', 'State', writable: true)
                ->field('requester', 'party', 'Requester')
                ->field('approver', 'party', 'Approver')
                ->field('approver_manager', 'party', "Approver's manager", path: 'approver.manager'),
        );

        Workflows::subjects()->register(Workflows::subject('team', Team::class)->ownerType()->field('name', 'string', 'Name'));

        foreach (['legal' => 'Legal', 'cfo' => 'CFO', 'admin' => 'Admin', 'user' => 'User'] as $key => $name) {
            Teams::roles()->register($key, $name);
        }
    }
}
