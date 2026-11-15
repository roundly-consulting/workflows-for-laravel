<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

/**
 * @param  array<string, mixed>  $config
 * @param  list<array<string, mixed>>  $escalations
 * @return array<string, mixed>
 */
function exclusionFlow(array $config, array $escalations = []): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', $config, $escalations === [] ? [] : ['escalations' => $escalations]),
        node('yes', 'end'), node('no', 'end'), node('late', 'end'),
    ], [['start', 'approve'], ['approve', 'yes', 'approved'], ['approve', 'no', 'rejected'], ['approve', 'late', 'expired']], ['timezone' => 'UTC']);
}

/**
 * A team where Rita (the requester) and Adam are both CFOs; Rita starts the approval.
 *
 * @param  array<string, mixed>  $config
 * @param  list<array<string, mixed>>  $escalations
 * @param  array<string, User>  $members  more members, role => user
 * @return array{0: Team, 1: PurchaseRequest, 2: WorkflowInstance, 3: User, 4: User}
 */
function requestedByRita(array $config, array $escalations = [], array $members = []): array
{
    $rita = user('Rita');
    $adam = user('Adam');
    $team = team('Acme', ['cfo' => $rita, ...$members]);
    Teams::for($team)->members()->add($adam, 'cfo');
    published('approve', exclusionFlow($config, $escalations), $team, 'purchase_request');
    $request = purchase($team, ['requester_id' => $rita->id]);
    $instance = Workflows::start('approve', $request, $team)->by($rita)->dispatch()->instance ?? throw new LogicException;

    return [$team, $request, $instance, $rita, $adam];
}

it('keeps the starter from deciding their own request while the other approvers can', function (): void {
    [, $request, $instance, $rita, $adam] = requestedByRita([
        'approvers' => [['kind' => 'role', 'role' => 'cfo']],
        'rule' => 'any',
        'exclude' => [['kind' => 'triggered_by']],
    ]);
    $round = ApprovalRequest::query()->sole();

    expect(array_column($round->excludedApprovers(), 'id'))->toBe([$rita->id])
        ->and(fn () => Approvals::as($rita)->for($request)->within($round)->approve())->toThrow(UnauthorizedApprovalException::class)
        ->and($instance->refresh())->toBeWaitingAt('approve');

    Approvals::as($adam)->for($request)->within($round)->approve();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'yes']);
});

it('excludes the subject\'s creator from every stage through a party field', function (): void {
    [, $request, $instance, $rita, $adam] = requestedByRita([
        'stages' => [['name' => 'Finance', 'approvers' => [['kind' => 'field', 'field' => 'subject.requester'], ['kind' => 'role', 'role' => 'cfo']], 'rule' => 'unanimous']],
        'exclude' => [['kind' => 'field', 'field' => 'subject.requester']],
    ]);
    $round = ApprovalRequest::query()->sole();

    // Unanimous among those asked: Adam alone decides, Rita was never asked.
    expect(array_column($round->stages()->sole()->namedApprovers(), 'id'))->toBe([$adam->id])
        ->and(fn () => Approvals::as($rita)->for($request)->within($round)->reject())->toThrow(UnauthorizedApprovalException::class);

    Approvals::as($adam)->for($request)->within($round)->approve();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'yes']);
});

it('carries the exclusion into an approvals preset', function (): void {
    config()->set('approvals.workflows', ['payout' => ['stages' => [['name' => 'Finance', 'rule' => 'any', 'required_approvers' => 1]]]]);

    [, $request, , $rita, $adam] = requestedByRita([
        'preset' => 'payout',
        'approvers' => [[['kind' => 'role', 'role' => 'cfo']]],
        'exclude' => [['kind' => 'user', 'id' => user('Nobody')->id], ['kind' => 'triggered_by']],
    ]);
    $round = ApprovalRequest::query()->sole();

    expect($round->workflow)->toBe('payout')
        ->and(array_column($round->stages()->sole()->namedApprovers(), 'id'))->toBe([$adam->id])
        ->and(array_column($round->excludedApprovers(), 'id'))->toContain($rita->id)
        ->and(fn () => Approvals::as($rita)->for($request)->within($round)->approve())->toThrow(UnauthorizedApprovalException::class);
});

it('fails no_approvers when everyone who could decide is excluded', function (): void {
    $rita = user('Rita');
    $team = team('Acme', ['cfo' => $rita]);
    published('approve', exclusionFlow(['approvers' => [['kind' => 'role', 'role' => 'cfo']], 'exclude' => [['kind' => 'triggered_by']]]), $team, 'purchase_request');

    $instance = Workflows::start('approve', purchase($team), $team)->by($rita)->dispatch()->instance;

    expect($instance)->toHaveFailedWith('no_approvers')
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('never hands a reassigned stage to an excluded actor', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    $lea = user('Lea');
    [, $request, $instance, $rita, $adam] = requestedByRita([
        'stages' => [['name' => 'Finance', 'approvers' => [['kind' => 'role', 'role' => 'legal']]]],
        'exclude' => [['kind' => 'triggered_by']],
        'expires_in' => 'P5D',
    ], [['after' => 'P1D', 'actions' => [['type' => 'reassign', 'to' => [['kind' => 'role', 'role' => 'cfo']]]]]], ['legal' => $lea]);
    $round = ApprovalRequest::query()->sole();

    expect(array_column($round->stages()->sole()->namedApprovers(), 'id'))->toBe([$lea->id]);

    $this->travelAndWake('P1D');

    // Replaced in place: Rita, a CFO too, is never asked.
    expect(ApprovalRequest::query()->count())->toBe(1)
        ->and(array_column($round->refresh()->stages()->sole()->namedApprovers(), 'id'))->toBe([$adam->id])
        ->and(fn () => Approvals::as($rita)->for($request)->within($round)->approve())->toThrow(UnauthorizedApprovalException::class);

    Approvals::as($adam)->for($request)->within($round)->approve();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'yes']);
});

it('validates the exclusion list at publish', function (array $config, ?ValidationCode $code, string $path): void {
    $report = Workflows::versions()->validate(exclusionFlow($config), 'purchase_request', team());

    $code === null
        ? expect($report->valid())->toBeTrue()
        : expect($report->has($code, 'approve'))->toBeTrue()
            ->and(array_map(static fn ($error): ?string => $error->path, $report->errors))->toContain($path);
})->with([
    'a valid list' => [['approvers' => [['kind' => 'role', 'role' => 'cfo']], 'exclude' => [['kind' => 'triggered_by'], ['kind' => 'field', 'field' => 'subject.requester']]], null, ''],
    'an unknown field' => [['approvers' => [['kind' => 'role', 'role' => 'cfo']], 'exclude' => [['kind' => 'field', 'field' => 'subject.creator']]], ValidationCode::UnknownField, '/exclude/0/field'],
    'a field that is no party' => [['approvers' => [['kind' => 'role', 'role' => 'cfo']], 'exclude' => [['kind' => 'field', 'field' => 'subject.title']]], ValidationCode::InvalidValue, '/exclude/0/field'],
    'an empty list' => [['approvers' => [['kind' => 'role', 'role' => 'cfo']], 'exclude' => []], ValidationCode::InvalidConfig, '/exclude'],
    'every flat approver excluded' => [['approvers' => [['kind' => 'triggered_by']], 'exclude' => [['kind' => 'triggered_by'], ['kind' => 'role', 'role' => 'legal']]], ValidationCode::ApproversExcluded, '/approvers'],
    'every approver of a stage excluded' => [['stages' => [['approvers' => [['kind' => 'role', 'role' => 'cfo']]], ['approvers' => [['kind' => 'user', 'id' => 7]]]], 'exclude' => [['kind' => 'user', 'id' => '7']]], ValidationCode::ApproversExcluded, '/stages/1/approvers'],
    'every approver of a preset stage excluded' => [['preset' => 'payout', 'approvers' => [[['kind' => 'triggered_by']]], 'exclude' => [['kind' => 'triggered_by']]], ValidationCode::ApproversExcluded, '/approvers/0'],
]);
