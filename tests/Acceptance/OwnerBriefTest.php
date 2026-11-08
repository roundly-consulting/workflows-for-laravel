<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;
use RoundlyConsulting\Workflows\Contracts\ResolvesRoles;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Events\DefinitionRetired;
use RoundlyConsulting\Workflows\Events\DraftSaved;
use RoundlyConsulting\Workflows\Events\EscalationFailed;
use RoundlyConsulting\Workflows\Events\EscalationTriggered;
use RoundlyConsulting\Workflows\Events\InstanceCancelled;
use RoundlyConsulting\Workflows\Events\InstanceCompleted;
use RoundlyConsulting\Workflows\Events\InstanceFailed;
use RoundlyConsulting\Workflows\Events\InstanceResumed;
use RoundlyConsulting\Workflows\Events\InstanceRetried;
use RoundlyConsulting\Workflows\Events\InstanceStarted;
use RoundlyConsulting\Workflows\Events\InstanceWaiting;
use RoundlyConsulting\Workflows\Events\StepCompleted;
use RoundlyConsulting\Workflows\Events\StepFailed;
use RoundlyConsulting\Workflows\Events\StepStarted;
use RoundlyConsulting\Workflows\Events\VersionPublished;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Exceptions\SubjectOwnerMismatchException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Task;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\SpyStep;
use RoundlyConsulting\Workflows\WorkflowsManager;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function briefTime(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Bratislava')->utc();
}

/**
 * "amount > 50000 needs approvals from Legal and CFO", as data.
 *
 * @return array<string, mixed>
 */
function legalAndCfo(): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('big', 'condition', ['when' => ['all' => [['field' => 'subject.amount', 'op' => 'gt', 'value' => '50000']]]]),
        node('legal_cfo', 'approval', ['stages' => [
            ['name' => 'Legal', 'approvers' => [['kind' => 'role', 'role' => 'legal']], 'rule' => 'any'],
            ['name' => 'CFO', 'approvers' => [['kind' => 'role', 'role' => 'cfo']], 'rule' => 'any'],
        ]]),
        node('done', 'end'),
    ], [
        ['start', 'big'], ['big', 'legal_cfo', 'true'], ['big', 'done', 'false'],
        ['legal_cfo', 'done', 'approved'], ['legal_cfo', 'done', 'rejected'], ['legal_cfo', 'done', 'expired'],
    ], ['timezone' => 'Europe/Bratislava']);
}

// A-1
it('needs Legal and CFO approval above 50000, configured purely as JSON data', function (): void {
    $lawyer = user('Lea');
    $cfo = user('Carl');
    $team = team('Acme', ['legal' => $lawyer, 'cfo' => $cfo]);
    published('legal-cfo', legalAndCfo(), $team, 'purchase_request');

    $big = Workflows::start('legal-cfo', purchase($team, ['amount' => '60000']), $team)->dispatch()->instance;
    $small = Workflows::start('legal-cfo', purchase($team, ['amount' => '40000']), $team)->dispatch()->instance;

    $round = ApprovalRequest::query()->sole();
    $stages = $round->stages()->orderBy('position')->get();

    expect($big)->toBeWaitingAt('legal_cfo')
        ->and($round->staged)->toBeTrue()
        ->and($stages->pluck('name')->all())->toBe(['Legal', 'CFO'])
        ->and(array_column($stages[0]->namedApprovers(), 'id'))->toBe([$lawyer->id])
        ->and(array_column($stages[1]->namedApprovers(), 'id'))->toBe([$cfo->id])
        ->and($small)->toHaveCompletedVia(['start', 'big', 'done']);

    // Both stages approve: the instance completes.
    $request = PurchaseRequest::query()->withoutGlobalScopes()->findOrFail($big?->subject_id);
    Approvals::as($lawyer)->for($request)->within($round)->approve();
    Approvals::as($cfo)->for($request)->within($round)->approve();

    expect($big?->refresh())->toHaveCompletedVia(['start', 'big', 'legal_cfo', 'done']);
});

final class HostRoles implements ResolvesRoles
{
    /** @var array<string, list<Model>> */
    public static array $roles = [];

    public function members(?Model $owner, string $role): array
    {
        return self::$roles[$role] ?? [];
    }
}

it('needs Legal and CFO approval with a host role resolver (no teams)', function (): void {
    HostRoles::$roles = ['legal' => [user('Lea')], 'cfo' => [user('Carl')]];
    config()->set('workflows.parties.roles', HostRoles::class);
    $graph = legalAndCfo();
    $company = company();

    published('legal-cfo', $graph, $company, 'document');

    $instance = Workflows::start('legal-cfo', document($company, ['amount' => '60000']), $company)->dispatch()->instance;

    expect($instance)->toBeWaitingAt('legal_cfo')
        ->and(ApprovalRequest::query()->sole()->stages()->count())->toBe(2);
});

// A-2
it('branches a rejected approval to a notify step and requests the rejected transition', function (): void {
    $approver = user('Adam');
    $requester = user('Rita');
    $team = team('Acme', ['user' => $approver, 'admin' => $requester]);

    published('review', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']),
        node('tell', 'notify', ['notification' => 'purchase.rejected', 'recipients' => [['kind' => 'field', 'field' => 'subject.requester']]]),
        node('reject', 'transition', ['transition' => 'reject']),
        node('done', 'end'),
    ], [
        ['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'tell', 'rejected'], ['approve', 'done', 'expired'],
        ['tell', 'reject'], ['reject', 'done', 'transitioned'],
    ]), $team, 'purchase_request');

    $request = purchase($team, ['requester_id' => $requester->id, 'approver_id' => $approver->id]);
    $instance = Workflows::start('review', $request, $team)->dispatch()->instance ?? throw new LogicException;

    Approvals::as($approver)->for($request)->within(ApprovalRequest::query()->sole())->reject();

    $tell = $instance->stepRuns()->where('node_id', 'tell')->sole();
    $item = InboxItem::query()->where('type', 'purchase.rejected')->sole();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'approve', 'tell', 'reject', 'done'])
        ->and($item->recipient_id)->toBe($requester->id)
        ->and($tell->output)->toBe(['recipients' => 1])
        ->and(DB::table('notification_receipts')->count())->toBe(1)
        ->and(Teams::runAs($team, fn () => $request->refresh()->state))->toBe('rejected');
});

// A-3
it('escalates an approval unanswered for two business days', function (): void {
    Event::fake([EscalationTriggered::class]);
    $this->travelTo(briefTime('2026-11-06 10:00'));   // Friday
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    ($this->chase)($team);
    published('escalate', escalatingApproval('weekdays'), $team, 'purchase_request');

    $instance = Workflows::start('escalate', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch()->instance ?? throw new LogicException;

    $this->travelAndWake(briefTime('2026-11-09 10:00'));    // Monday
    Event::assertNotDispatched(EscalationTriggered::class);

    $this->travelAndWake(briefTime('2026-11-10 10:00'));    // Tuesday
    Event::assertDispatched(EscalationTriggered::class, fn (EscalationTriggered $event): bool => $event->level === 1 && $event->instance->is($instance));
});

it('escalates on an opening-hours calendar, skipping its holiday', function (): void {
    Event::fake([EscalationTriggered::class]);
    $this->travelTo(briefTime('2026-11-06 10:00'));   // Friday
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    OpeningHours::sync($team, [
        'timezone' => 'Europe/Bratislava',
        'week' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['09:00-17:00']),
        'exceptions' => [['date' => '2026-11-09']],   // a holiday Monday
    ], 'office');

    ($this->chase)($team);
    published('escalate', escalatingApproval('opening_hours:office'), $team, 'purchase_request');
    Workflows::start('escalate', purchase($team, ['approver_id' => $approver->id]), $team)->dispatch();

    $this->travelAndWake(briefTime('2026-11-10 10:00'));    // Tuesday: only one business day
    Event::assertNotDispatched(EscalationTriggered::class);

    $this->travelAndWake(briefTime('2026-11-11 10:00'));    // Wednesday
    Event::assertDispatched(EscalationTriggered::class);
});

it('cancels the escalation when the approval is decided first', function (): void {
    Event::fake([EscalationTriggered::class]);
    $this->travelTo(briefTime('2026-11-06 10:00'));
    $approver = user('Adam');
    $team = team('Acme', ['user' => $approver]);
    ($this->chase)($team);
    published('escalate', escalatingApproval('weekdays'), $team, 'purchase_request');

    $request = purchase($team, ['approver_id' => $approver->id]);
    $instance = Workflows::start('escalate', $request, $team)->dispatch()->instance ?? throw new LogicException;
    Approvals::as($approver)->for($request)->within(ApprovalRequest::query()->sole())->approve();

    $this->travelAndWake(briefTime('2026-11-10 10:00'));

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and($instance->escalations()->sole()->status->value)->toBe('cancelled');
    Event::assertNotDispatched(EscalationTriggered::class);
});

/**
 * @return array<string, mixed>
 */
function escalatingApproval(string $calendar): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any'], ['escalations' => [
            ['after' => ['amount' => 2, 'unit' => 'business_days', 'calendar' => $calendar], 'actions' => [['type' => 'start_workflow', 'definition' => 'chase']]],
        ]]),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']], ['timezone' => 'Europe/Bratislava']);
}

beforeEach(function (): void {
    // The escalation target every brief case may start.
    $this->chase = static function (Team|Model|null $owner): void {
        published('chase', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $owner, null);
    };
});

// A-4
it('keeps a thirty-day wait across a simulated restart', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    published('wait', graph([node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'delay', ['for' => 'P30D']), node('done', 'end')], [['start', 'wait'], ['wait', 'done']]), null, null);

    $instance = Workflows::start('wait')->dispatch()->instance ?? throw new LogicException;
    $this->runWorkflows();

    DB::table('jobs')->delete();
    app()->forgetInstance(WorkflowsManager::class);

    $this->travelAndWake(CarbonImmutable::parse('2026-12-02 08:59', 'UTC'));
    expect($instance->refresh())->toBeWaitingAt('wait');

    $this->travelAndWake(CarbonImmutable::parse('2026-12-02 09:00', 'UTC'));
    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

// A-5
it('does not duplicate the effect when a failed side-effect step is retried', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    published('task', graph([node('start', 'trigger', ['trigger' => 'manual']), node('task', 'acme.create_task', ['title' => 'Once', 'crash_after_effect' => true]), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    $instance = Workflows::start('task')->dispatch()->instance ?? throw new LogicException;
    $this->travelAndWake('PT1M');

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(Task::query()->count())->toBe(1)
        ->and($instance->stepRuns()->where('node_id', 'task')->pluck('idempotency_key')->unique()->count())->toBe(1);
});

// A-6
it('leaves running v1 instances on v1 after v2 is published', function (): void {
    $version = static fn (string $next): array => graph([
        node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'go.on']), node($next, 'acme.spy'), node('done', 'end'),
    ], [['start', 'wait'], ['wait', $next, 'received'], [$next, 'done']]);

    published('flow', $version('v1_step'), null, null);
    $old = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::definition('flow')->edit($version('v2_step'));
    Workflows::definition('flow')->publish();
    $new = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    Workflows::emit('go.on');

    expect($old->refresh()->path())->toBe(['start', 'wait', 'v1_step', 'done'])
        ->and($new->refresh()->path())->toBe(['start', 'wait', 'v2_step', 'done']);
});

/**
 * @return array<string, mixed>
 */
function transitionFlow(bool $deniedBranch, string $transition = 'approve'): array
{
    $edges = [['start', 'go'], ['go', 'done', 'transitioned']];

    if ($deniedBranch) {
        $edges[] = ['go', 'refused', 'denied'];
    }

    return graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('go', 'transition', ['transition' => $transition]),
        node('done', 'end'),
        ...($deniedBranch ? [node('refused', 'end')] : []),
    ], $edges);
}

// A-7
it('fails the step cleanly when a lifecycle guard blocks the transition', function (): void {
    Event::fake([StepFailed::class, InstanceFailed::class]);
    $team = team();
    published('go', transitionFlow(false), $team, 'purchase_request');
    $request = purchase($team, ['title' => 'blocked']);

    $instance = Workflows::start('go', $request, $team)->dispatch()->instance ?? throw new LogicException;
    $run = $instance->stepRuns()->where('node_id', 'go')->sole();

    expect($instance)->toHaveFailedWith('transition_denied:budget')
        ->and($run->status)->toBe(StepRunStatus::Failed)
        ->and($run->error_retryable)->toBeFalse()
        ->and($instance->stepRuns()->where('node_id', 'go')->count())->toBe(1)
        ->and(Teams::runAs($team, fn () => $request->refresh()->state))->toBe('pending');

    Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => ! $event->willRetry);
    Event::assertDispatched(InstanceFailed::class);
});

it('follows the denied branch when the graph has one', function (): void {
    $team = team();
    published('go', transitionFlow(true), $team, 'purchase_request');

    $instance = Workflows::start('go', purchase($team, ['title' => 'blocked']), $team)->dispatch()->instance;

    expect($instance)->toHaveCompletedVia(['start', 'go', 'refused'])
        ->and($instance?->stepRuns()->where('node_id', 'go')->sole()->output['code'])->toBe('budget');
});

it('fails cleanly on a transition illegal from the current state', function (): void {
    $team = team();
    published('go', transitionFlow(false, 'reject'), $team, 'purchase_request');
    $request = purchase($team);
    Teams::runAs($team, fn () => Lifecycles::for($request)->asSystem()->apply('hold'));

    expect(Workflows::start('go', $request, $team)->dispatch()->instance)->toHaveFailedWith('transition_denied:not_from_current_state');
});

// A-8
it('starts one instance per duplicate trigger idempotency key', function (): void {
    published('once', graph([node('start', 'trigger', ['trigger' => 'event', 'event' => 'thing.happened']), node('done', 'end')], [['start', 'done']]), null, null);

    Workflows::emit('thing.happened', idempotencyKey: 'evt-1');
    Workflows::emit('thing.happened', idempotencyKey: 'evt-1');

    expect(WorkflowInstance::query()->count())->toBe(1);
});

// A-9
it('reports an unreachable node and a missing rejected branch with their node ids', function (): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'triggered_by']], 'rule' => 'any']),
        node('orphan', 'acme.spy'),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'expired'], ['orphan', 'done']]), 'purchase_request');

    $codes = array_map(static fn ($error): array => ['code' => $error->code->value, 'node' => $error->node, 'params' => $error->params], $report->errors);

    expect($codes)->toContain(['code' => 'unreachable_node', 'node' => 'orphan', 'params' => []])
        ->and($codes)->toContain(['code' => 'missing_outcome', 'node' => 'approve', 'params' => ['outcome' => 'rejected']]);
});

// A-10
it('rejects a cycle without a delay', function (): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']), node('a', 'acme.spy'), node('b', 'acme.spy'), node('done', 'end'),
    ], [['start', 'a'], ['a', 'b'], ['b', 'a']]), null);

    expect($report->has(ValidationCode::CycleWithoutBreaker, 'a'))->toBeTrue();
});

// A-11
it('stops runaway workflows at the step limit and the nesting depth', function (): void {
    published('loop', graph([node('start', 'trigger', ['trigger' => 'manual']), node('spy', 'acme.spy'), node('again', 'loop', ['max' => 999]), node('done', 'end')], [['start', 'spy'], ['spy', 'again'], ['again', 'spy', 'continue'], ['again', 'done', 'exhausted']]), null, null);
    published('nest', graph([node('start', 'trigger', ['trigger' => 'manual']), node('again', 'start_workflow', ['definition' => 'nest', 'wait' => false]), node('done', 'end')], [['start', 'again'], ['again', 'done', 'started']]), null, null);

    expect(Workflows::start('loop')->dispatch()->instance)->toHaveFailedWith('step_limit_exceeded');

    Workflows::start('nest')->dispatch();

    expect(WorkflowInstance::query()->where('failure_code', 'nesting_depth_exceeded')->sole()->depth)->toBe(3);
});

// A-12 (with teams)
it('never reads or writes a subject of another team', function (): void {
    $acme = team('Acme');
    $globex = team('Globex');
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']), node('wait', 'wait', ['event' => 'go.on', 'match' => 'any']), node('spy', 'acme.spy'), node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'spy', 'received'], ['spy', 'done']]), $acme, 'purchase_request');

    expect(fn () => Workflows::start('flow', purchase($globex), $acme)->dispatch())->toThrow(SubjectOwnerMismatchException::class);

    $request = purchase($acme);
    $instance = Workflows::start('flow', $request, $acme)->dispatch()->instance ?? throw new LogicException;

    // Moved to Globex mid-run.
    PurchaseRequest::query()->withoutGlobalScopes()->whereKey($request->id)->update(['team_id' => $globex->id]);
    Teams::runAs($acme, fn () => Workflows::emit('go.on', owner: $acme));

    expect($instance->refresh()->failure_code)->toBeIn(['owner_mismatch', 'subject_missing'])
        ->and(SpyStep::$calls)->toBe(0);
});

// A-13
it('writes nothing on a dry run and returns the correct path', function (): void {
    $team = team('Acme', ['legal' => user('Lea'), 'cfo' => user('Carl')]);
    $graph = legalAndCfo();
    $graph['nodes'][] = node('notify_approved', 'notify', ['notification' => 'purchase.approved', 'recipients' => [['kind' => 'triggered_by']]]);
    $graph['edges'] = array_map(static fn (array $edge): array => $edge['from'] === 'legal_cfo' && ($edge['outcome'] ?? null) === 'approved' ? ['from' => 'legal_cfo', 'to' => 'notify_approved', 'outcome' => 'approved'] : $edge, $graph['edges']);
    $graph['edges'][] = ['from' => 'notify_approved', 'to' => 'done'];
    published('legal-cfo', $graph, $team, 'purchase_request');
    $request = purchase($team, ['amount' => '60000']);

    Event::fake([DefinitionRetired::class, DraftSaved::class, EscalationFailed::class, EscalationTriggered::class, InstanceCancelled::class, InstanceCompleted::class, InstanceFailed::class, InstanceResumed::class, InstanceRetried::class, InstanceStarted::class, InstanceWaiting::class, StepCompleted::class, StepFailed::class, StepStarted::class, VersionPublished::class]);
    $writes = 0;
    DB::listen(static function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)/i', $query->sql) === 1) {
            $writes++;
        }
    });

    $result = Workflows::definition('legal-cfo', $team)->dryRun()->subject($request)->run();

    expect($writes)->toBe(0)
        ->and($result->nodes())->toBe(['start', 'big', 'legal_cfo', 'notify_approved', 'done'])
        ->and($result->path[2]->assumed)->toBeTrue()
        ->and($result->path[2]->outcome)->toBe('approved')
        ->and(collect($result->actions)->map(fn ($action): string => $action->node.':'.$action->status)->all())->toBe(['legal_cfo:would_run', 'notify_approved:simulated'])
        ->and(WorkflowInstance::query()->count())->toBe(0);

    Event::assertNothingDispatched();
});

// A-14
it('remaps ids on template export and import, clearing another owner\'s references', function (): void {
    $acme = team('Acme');
    $globex = team('Globex');
    $graph = graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('approve', 'approval', ['approvers' => [['kind' => 'user', 'id' => 42]], 'rule' => 'any', 'expires_in' => ['amount' => 2, 'unit' => 'business_days', 'calendar' => 'opening_hours:office']]),
        node('done', 'end'),
    ], [['start', 'approve'], ['approve', 'done', 'approved'], ['approve', 'done', 'rejected'], ['approve', 'done', 'expired']], ['calendar' => 'opening_hours:office']);
    OpeningHours::sync($acme, ['week' => ['monday' => ['09:00-17:00']]], 'office');
    published('approve', $graph, $acme, 'purchase_request');

    $result = Workflows::versions()->import(Workflows::definition('approve', $acme)->export(), $globex) ?? throw new LogicException;
    $nodes = collect($result->version->graph['nodes'])->keyBy('id');

    expect(array_values($result->idMap))->each->toMatch('/^n_[a-z2-7]{10}$/')
        ->and(collect($result->flags)->map(fn ($flag): string => $flag->node.' '.$flag->path)->all())->toEqualCanonicalizing([
            '* /settings/calendar',
            $result->idMap['approve'].' /config/approvers/0/id',
            $result->idMap['approve'].' /config/expires_in/calendar',
        ])
        ->and($nodes[$result->idMap['approve']]['config']['approvers'][0]['id'])->toBeNull()
        ->and($result->version->graph['settings']['calendar'])->toBeNull()
        ->and(fn () => Workflows::definition('approve', $globex)->publish())->toThrow(InvalidGraphException::class);
});
