<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamMembership;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamRoles;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamsBridge;
use RoundlyConsulting\Workflows\Bridges\Teams\TeamsOwnerContext;
use RoundlyConsulting\Workflows\Contracts\ChecksOwnerMembership;
use RoundlyConsulting\Workflows\Contracts\RestoresOwnerContext;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Adapters;
use RoundlyConsulting\Workflows\Support\Engine\EngineQueue;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Company;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\SpyStep;

function spyOn(): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'go.on']),
        node('spy', 'acme.spy'),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'spy', 'received'], ['spy', 'done']]);
}

it('binds the teams owner context, roles and membership', function (): void {
    expect(app(RestoresOwnerContext::class))->toBeInstanceOf(TeamsOwnerContext::class)
        ->and(app(ChecksOwnerMembership::class))->toBeInstanceOf(TeamMembership::class)
        ->and(Adapters::roles())->toBeInstanceOf(TeamRoles::class);
});

it('resolves roles to active memberships only', function (): void {
    $active = user('Ann');
    $expired = user('Eve');
    $team = team('Acme', ['legal' => $active]);
    Teams::for($team)->members()->add($expired, 'legal', [], CarbonImmutable::now()->subDay());

    expect(array_map(static fn ($user) => $user->name, app(TeamRoles::class)->members($team, 'legal')))->toBe(['Ann'])
        ->and(app(TeamRoles::class)->members(company(), 'legal'))->toBe([])
        ->and(app(TeamMembership::class)->isMember($team, $active))->toBeTrue()
        ->and(app(TeamMembership::class)->isMember($team, $expired))->toBeFalse()
        ->and(app(TeamMembership::class)->isMember(null, $expired))->toBeTrue();
});

// C-87: a queued step runs as the instance's team, whatever context dispatched it.
it('runs queued steps in the instance owner context', function (): void {
    $acme = team('Acme');
    $globex = team('Globex');
    published('flow', spyOn(), $acme, 'purchase_request');
    $instance = Workflows::start('flow', purchase($acme), $acme)->dispatch()->instance ?? throw new LogicException;

    // Resumed from a request running as Globex.
    Teams::runAs($globex, fn () => Workflows::emit('go.on', PurchaseRequest::query()->withoutGlobalScopes()->find($instance->subject_id), owner: null));

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(SpyStep::$contexts[0]->subject()?->getKey())->toBe($instance->subject_id);
});

it('runs queued steps without teams context too (no team owner)', function (): void {
    published('flow', spyOn(), company(), 'document');
    $company = Company::query()->sole();
    $instance = Workflows::start('flow', document($company), $company)->dispatch()->instance ?? throw new LogicException;

    Workflows::emit('go.on', Document::query()->find($instance->subject_id));

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

// C-88: a scoped subject that is not visible to the owner reads as missing.
it('fails with subject_missing when the subject left the owner scope', function (): void {
    $acme = team('Acme');
    published('flow', spyOn(), null, 'purchase_request');
    $request = purchase($acme);
    $instance = Workflows::start('flow', $request)->dispatch()->instance ?? throw new LogicException;

    // App-wide definition: the instance owner is the subject's team (C-145).
    expect($instance->owner_id)->toBe($acme->getKey());

    PurchaseRequest::query()->withoutGlobalScopes()->whereKey($request->id)->delete();
    Teams::runAs($acme, fn () => Workflows::emit('go.on', owner: $acme));

    $wait = $instance->waits()->first() ?? throw new LogicException;
    app(EngineQueue::class)->resume($wait->step_run_id, $wait->id, SignalKind::Event);

    expect($instance->refresh())->toHaveFailedWith('subject_missing');
});

// C-145: an app-wide definition over a team-scoped subject runs in the subject's team.
it('runs app-wide definitions in the subject owner context', function (): void {
    $acme = team('Acme');
    published('flow', spyOn(), null, 'purchase_request');
    $instance = Workflows::start('flow', purchase($acme))->dispatch()->instance ?? throw new LogicException;

    // Woken from nowhere (wake has no team context): the subject is still found.
    Workflows::emit('go.on', owner: $acme);
    $wait = $instance->waits()->where('status', 'pending')->first();

    if ($wait !== null) {
        app(EngineQueue::class)->resume($wait->step_run_id, $wait->id, SignalKind::Event);
    }

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)->and(SpyStep::$calls)->toBe(1);
});

// C-143: a condition is evaluated in the instance's context, even from another team's request
// and with scoped reads throwing outside any context.
it('evaluates condition waits in the owner context', function (): void {
    config()->set('teams.scoping.without_context', 'throw');
    $acme = team('Acme');
    $globex = team('Globex');
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['condition' => ['field' => 'subject.title', 'op' => 'equals', 'value' => 'ready']]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'met']]), $acme, 'purchase_request');

    $request = purchase($acme);
    $instance = Teams::runAs($acme, fn () => Workflows::start('flow', $request, $acme)->dispatch()->instance) ?? throw new LogicException;

    Teams::runAs($acme, fn () => PurchaseRequest::query()->whereKey($request->id)->update(['title' => 'ready']));
    Teams::runAs($globex, fn () => Workflows::for($request)->touch());

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

it('reports a subject without a team as owned by none', function (): void {
    $request = new PurchaseRequest(['team_id' => null]);

    expect(TeamsBridge::ownerOf($request))->toBeNull()
        ->and(TeamsBridge::ownerOf(document()))->toBeFalse();
});
