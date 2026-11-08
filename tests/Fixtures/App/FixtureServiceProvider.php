<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\CreateTaskStep;

/**
 * The host app: registers the subject and owner types in boot() (after the package booted),
 * a host step type, and policies. The system actor is `null` unless a test sets a service user.
 */
final class FixtureServiceProvider extends ServiceProvider
{
    public static bool $allow = true;

    public function boot(): void
    {
        Workflows::subjects()->register(
            Workflows::subject('document', Document::class)
                ->label('Document')
                ->field('title', 'string', 'Title', writable: true)
                ->field('amount', 'decimal', 'Amount')
                ->field('priority', 'string', 'Priority', writable: true)
                ->field('department', 'enum', 'Department', options: ['legal', 'finance', 'sales'])
                ->field('urgent', 'boolean', 'Urgent')
                ->field('due_on', 'date', 'Due on')
                ->field('requester', 'party', 'Requester')
                ->field('approver', 'party', 'Approver')
                ->field('approver_manager', 'party', "Approver's manager", path: 'approver.manager'),
        );

        Workflows::subjects()->register(
            Workflows::subject('company', Company::class)->label('Company')->ownerType()->field('plan', 'string', 'Plan'),
        );

        Workflows::catalogue()->register(CreateTaskStep::class);

        foreach (['update', 'createTask'] as $ability) {
            Gate::define($ability, static fn (?User $user, ?Document $document = null): bool => self::$allow);
        }
    }
}
