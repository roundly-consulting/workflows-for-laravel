<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Workflows\Actions\Engine\RecordStepResultAction;
use RoundlyConsulting\Workflows\Actions\Engine\ResumeStepAction;
use RoundlyConsulting\Workflows\Calendars\CalendarRegistry;
use RoundlyConsulting\Workflows\Calendars\WeekdayCalendar;
use RoundlyConsulting\Workflows\DataTransferObjects\RetryPolicy;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Enums\SignalKind;
use RoundlyConsulting\Workflows\Enums\StepRunStatus;
use RoundlyConsulting\Workflows\Enums\WaitKind;
use RoundlyConsulting\Workflows\Enums\WaitStatus;
use RoundlyConsulting\Workflows\Exceptions\InstanceNotCancellableException;
use RoundlyConsulting\Workflows\Exceptions\InstanceNotRetryableException;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\When;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Jobs\RunStepJob;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Support\BusinessClock;
use RoundlyConsulting\Workflows\Support\Clock;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\ConditionWaits;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Engine\RetryPolicies;
use RoundlyConsulting\Workflows\Support\StepCatalogue;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Task;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\CreateTaskStep;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\FlakyState;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps\SpyStep;
use RoundlyConsulting\Workflows\WorkflowsManager;

// W-27: `composer mutate` mutates the pure core these tests pin.
mutates(BusinessClock::class, Durations::class, Evaluator::class);

function flow(array $nodes, array $edges, array $settings = []): array
{
    return graph([node('start', 'trigger', ['trigger' => 'manual']), ...$nodes], $edges, $settings);
}

function at(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// A-4: a 30-day wait survives a simulated restart.
it('keeps a thirty-day wait across a restart', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('queue.default', 'database');
    at('2026-11-02 09:00');

    published('flow', flow([node('wait', 'delay', ['for' => 'P30D']), node('spy', 'acme.spy'), node('done', 'end')], [['start', 'wait'], ['wait', 'spy'], ['spy', 'done']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $this->runWorkflows();

    expect($instance)->toBeWaitingAt('wait');

    // The restart: the queue is flushed and every package singleton forgotten.
    DB::table('jobs')->delete();
    foreach ([WorkflowsManager::class, StepCatalogue::class, SubjectRegistry::class, CalendarRegistry::class] as $singleton) {
        app()->forgetInstance($singleton);
    }

    $this->travelAndWake(CarbonImmutable::parse('2026-12-02 08:59', 'UTC'));

    expect($instance)->toBeWaitingAt('wait')->and(SpyStep::$calls)->toBe(0);

    $this->travelAndWake(CarbonImmutable::parse('2026-12-02 09:00', 'UTC'));

    expect($instance->refresh())->toHaveCompletedVia(['start', 'wait', 'spy', 'done'])->and(SpyStep::$calls)->toBe(1);
});

// A-5: a retry never repeats a side effect recorded with once().
it('does not duplicate the effect of a retried side-effect step', function (): void {
    at('2026-11-02 09:00');
    published('flow', flow([node('task', 'acme.create_task', ['title' => 'Review', 'crash_after_effect' => true]), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect($instance->refresh()->status)->toBe(InstanceStatus::Running)
        ->and(Task::query()->count())->toBe(1);

    $this->travelAndWake('PT1M');

    $attempts = $instance->stepRuns()->where('node_id', 'task')->orderBy('attempt')->get();

    expect($instance->refresh())->toHaveCompletedVia(['start', 'task', 'done'])
        ->and(Task::query()->count())->toBe(1)
        ->and(CreateTaskStep::$calls)->toBe(2)
        ->and($attempts->pluck('status')->map->value->all())->toBe(['failed', 'completed'])
        ->and($attempts->pluck('idempotency_key')->unique()->count())->toBe(1)
        ->and($attempts->last()?->output['task_id'])->toBe(Task::query()->value('id'));
});

it('does not duplicate the effect after a manual retry', function (): void {
    published('flow', flow([node('task', 'acme.create_task', ['title' => 'Review', 'fail_for_good' => true]), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect($instance)->toHaveFailedWith('downstream_refused')->and(Task::query()->count())->toBe(1);

    FlakyState::$failing = false;
    Workflows::instances()->retry($instance);

    expect($instance->refresh())->toHaveCompletedVia(['start', 'task', 'done'])
        ->and(Task::query()->count())->toBe(1)
        ->and($instance->stepRuns()->where('node_id', 'task')->count())->toBe(2);
});

// C-144: effect keys outlive the trigger-key TTL.
it('keeps effect keys past the trigger key TTL', function (): void {
    at('2026-11-02 09:00');
    published('flow', flow([node('task', 'acme.create_task', ['title' => 'Review', 'fail_for_good' => true]), node('done', 'end')], [['start', 'task'], ['task', 'done']]), null, null);

    $instance = Workflows::start('flow')->idempotencyKey('start:1')->dispatch()->instance ?? throw new LogicException;

    at('2027-01-15 09:00');
    Workflows::prune();
    FlakyState::$failing = false;
    Workflows::instances()->retry($instance);

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)
        ->and(Task::query()->count())->toBe(1)
        ->and(DB::table('workflow_idempotency_keys')->where('scope', 'start')->count())->toBe(0)
        ->and(DB::table('workflow_idempotency_keys')->where('scope', 'effect')->count())->toBe(1);
});

// A-6: publishing v2 leaves running v1 instances on v1.
it('leaves running v1 instances on v1 when v2 is published', function (): void {
    $v1 = flow([node('wait', 'wait', ['event' => 'doc.go']), node('old', 'acme.spy', ['mode' => 'ok']), node('done', 'end')], [['start', 'wait'], ['wait', 'old', 'received'], ['old', 'done']]);
    $v2 = flow([node('wait', 'wait', ['event' => 'doc.go']), node('new', 'acme.create_task', ['title' => 'v2']), node('done', 'end')], [['start', 'wait'], ['wait', 'new', 'received'], ['new', 'done']]);

    published('flow', $v1, null, null);
    $old = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    Workflows::definition('flow')->edit($v2);
    Workflows::definition('flow')->publish();
    $new = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    Workflows::emit('doc.go');

    expect($old->refresh())->toHaveCompletedVia(['start', 'wait', 'old', 'done'])
        ->and($old->version?->number)->toBe(1)
        ->and($new->refresh())->toHaveCompletedVia(['start', 'wait', 'new', 'done'])
        ->and($new->version?->number)->toBe(2);
});

// C-97 / C-98
it('proceeds at once from a delay already due, and fails on an empty date field', function (): void {
    at('2026-11-02 09:00');
    published('past', flow([node('wait', 'delay', ['until' => '2026-01-01T00:00:00Z']), node('done', 'end')], [['start', 'wait'], ['wait', 'done']]), null, null);
    published('field', flow([node('wait', 'delay', ['until_field' => 'subject.due_on', 'offset' => '-P1D']), node('done', 'end')], [['start', 'wait'], ['wait', 'done']]));

    expect(Workflows::start('past')->dispatch()->instance?->status)->toBe(InstanceStatus::Completed)
        ->and(Workflows::start('field', document())->dispatch()->instance)->toHaveFailedWith('delay_field_empty');

    $instance = Workflows::start('field', document(null, ['due_on' => '2026-11-10 12:00:00']))->dispatch()->instance;

    expect($instance)->toBeWaitingAt('wait')
        ->and($instance?->waits()->first()?->due_at?->format('Y-m-d H:i'))->toBe('2026-11-09 12:00');
});

it('delays by business days on the weekdays calendar', function (): void {
    at('2026-11-06 15:00');
    published('flow', flow([node('wait', 'delay', ['for' => ['amount' => 2, 'unit' => 'business_days']]), node('done', 'end')], [['start', 'wait'], ['wait', 'done']], ['calendar' => 'weekdays']), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance;

    expect($instance?->waits()->first()?->due_at?->format('D H:i'))->toBe('Tue 15:00');
});

// C-66 (core half): a calendar missing at run time fails the step.
it('fails a step whose calendar is missing at run time', function (): void {
    Workflows::calendars()->register('support', static fn (?Model $owner, string $timezone): WeekdayCalendar => new WeekdayCalendar($timezone));
    published('flow', flow([node('wait', 'delay', ['for' => ['amount' => 1, 'unit' => 'business_days', 'calendar' => 'support']]), node('done', 'end')], [['start', 'wait'], ['wait', 'done']]), null, null);
    app()->forgetInstance(CalendarRegistry::class);

    expect(Workflows::start('flow')->dispatch()->instance)->toHaveFailedWith('calendar_missing')
        ->and(Workflows::calendars()->names())->toBe(['weekdays']);
});

// C-99
it('proceeds at once from a condition wait already true, else waits and polls', function (): void {
    at('2026-11-02 09:00');
    $graph = flow([node('wait', 'wait', ['condition' => ['field' => 'subject.priority', 'op' => 'equals', 'value' => 'high'], 'poll' => 'PT30M']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'met']]);
    published('flow', $graph);

    expect(Workflows::start('flow', document(null, ['priority' => 'high']))->dispatch()->instance?->status)->toBe(InstanceStatus::Completed);

    $document = document(null, ['priority' => 'low']);
    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;
    $poll = $instance->waits()->first();

    expect($poll?->kind)->toBe(WaitKind::Condition)->and($poll?->due_at?->format('H:i'))->toBe('09:30');

    // Due, still false: polled again later.
    $this->travelAndWake(CarbonImmutable::parse('2026-11-02 09:30', 'UTC'));
    expect($instance->refresh()->status)->toBe(InstanceStatus::Waiting)->and($poll?->refresh()->due_at?->format('H:i'))->toBe('10:00');

    // Changed quietly (no model event): the next poll sees it.
    Document::query()->whereKey($document->id)->toBase()->update(['priority' => 'high']);
    $this->travelAndWake(CarbonImmutable::parse('2026-11-02 10:00', 'UTC'));

    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

// C-49: one fired wait cancels its siblings.
it('cancels sibling waits when one fires', function (): void {
    at('2026-11-02 09:00');
    published('flow', flow([node('wait', 'wait', ['event' => 'doc.go', 'timeout' => 'PT1H']), node('ok', 'end'), node('late', 'end')], [['start', 'wait'], ['wait', 'ok', 'received'], ['wait', 'late', 'timed_out']]), null, null);

    $first = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::emit('doc.go');

    expect($first->refresh())->toHaveCompletedVia(['start', 'wait', 'ok'])
        ->and($first->waits()->pluck('status', 'kind')->map->value->all())->toEqual(['event' => 'fired', 'timeout' => 'cancelled']);

    $second = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $this->travelAndWake('PT1H');

    expect($second->refresh())->toHaveCompletedVia(['start', 'wait', 'late'])
        ->and($second->waits()->pluck('status', 'kind')->map->value->all())->toEqual(['event' => 'cancelled', 'timeout' => 'fired']);
});

// C-51
it('cancels a wait whose step has moved on', function (): void {
    published('flow', flow([node('wait', 'wait', ['event' => 'doc.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $wait = $instance->waits()->first() ?? throw new LogicException;
    StepRun::query()->whereKey($wait->step_run_id)->update(['status' => StepRunStatus::Completed->value]);

    app(ResumeStepAction::class)->execute($wait->step_run_id, $wait->id, SignalKind::Event);

    expect($wait->refresh()->status)->toBe(WaitStatus::Cancelled);
});

// C-48
it('reverts a stale wait claim to pending', function (): void {
    at('2026-11-02 09:00');
    $wait = WorkflowWait::factory()->create(['status' => WaitStatus::Claimed, 'claimed_at' => Clock::now()->subMinutes(11), 'due_at' => Clock::now()->addDay()]);

    expect(Workflows::wake()->reclaimed)->toBe(1)->and($wait->refresh()->status)->toBe(WaitStatus::Pending);
});

// C-46
it('retries an attempt whose lease expired (a crashed worker)', function (): void {
    at('2026-11-02 09:00');
    published('flow', flow([node('spy', 'acme.spy'), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);
    config()->set('workflows.queue.enabled', 'on');
    Queue::fake();

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $run = $instance->stepRuns()->where('node_id', 'spy')->first() ?? throw new LogicException;
    $run->update(['status' => StepRunStatus::Running, 'claim_token' => 'CRASHED', 'lease_until' => Clock::now()->addMinutes(10), 'started_at' => Clock::now()]);

    at('2026-11-02 09:11');
    $report = Workflows::wake();

    expect($report->reclaimed)->toBe(1)
        ->and($run->refresh()->status)->toBe(StepRunStatus::Failed)
        ->and($run->error_code)->toBe('lease_expired')
        ->and($instance->stepRuns()->where('attempt', 2)->first()?->status)->toBe(StepRunStatus::Queued);
});

// C-47
it('requeues a queued step whose job was lost', function (): void {
    at('2026-11-02 09:00');
    config()->set('workflows.queue.enabled', 'on');
    Queue::fake();
    published('flow', flow([node('spy', 'acme.spy'), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);

    Workflows::start('flow')->dispatch();
    Queue::assertPushed(RunStepJob::class, 1);

    // Pushed a minute ago: not yet lost.
    at('2026-11-02 09:01');
    expect(Workflows::wake()->requeued)->toBe(0);

    at('2026-11-02 09:06');
    expect(Workflows::wake()->requeued)->toBe(1);
    Queue::assertPushed(RunStepJob::class, 2);

    at('2026-11-02 09:07');
    expect(Workflows::wake()->requeued)->toBe(0);
});

// C-54
it('resolves the retry policy node, then settings, then type, then config', function (): void {
    config()->set('workflows.retry.default', ['max_attempts' => 4, 'backoff' => [10], 'jitter' => 0]);
    config()->set('workflows.retry.max_attempts_cap', 5);

    $graph = GraphParser::parse(flow([node('a', 'acme.spy', [], ['retry' => ['max_attempts' => 9]]), node('b', 'acme.spy'), node('done', 'end')], [['start', 'a'], ['a', 'b'], ['b', 'done']], ['retry' => ['max_attempts' => 2, 'backoff' => [5, 50]]]));
    $plain = GraphParser::parse(flow([node('c', 'acme.spy'), node('done', 'end')], [['start', 'c'], ['c', 'done']]));

    $node = RetryPolicies::resolve($graph->node('a') ?? throw new LogicException, $graph, null);
    $settings = RetryPolicies::resolve($graph->node('b') ?? throw new LogicException, $graph, null);
    $default = RetryPolicies::resolve($plain->node('c') ?? throw new LogicException, $plain, null);

    expect($node->maxAttempts)->toBe(5)
        ->and($settings->maxAttempts)->toBe(2)->and($settings->delayBefore(2))->toBe(5)->and($settings->delayBefore(9))->toBe(50)
        ->and($default->maxAttempts)->toBe(4)->and($default->delayBefore(3))->toBe(10)
        ->and(RetryPolicies::delay(new RetryPolicy(3, [100], 0.5), 2))->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(150)
        ->and((new RetryPolicy(3, []))->delayBefore(2))->toBe(0)
        ->and(RetryPolicy::overlay('x', $default, 5))->toBeNull()
        ->and(RetryPolicy::overlay(['backoff' => [-1]], $default, 5))->toBeNull();
});

it('retries at the backoff and gives up after the last attempt', function (): void {
    at('2026-11-02 09:00');
    config()->set('workflows.retry.default', ['max_attempts' => 2, 'backoff' => [60], 'jitter' => 0]);
    published('flow', flow([node('spy', 'acme.spy', ['mode' => 'retryable']), node('done', 'end')], [['start', 'spy'], ['spy', 'done']]), null, null);

    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    $retry = $instance->waits()->where('kind', WaitKind::Retry->value)->first();

    expect($retry?->due_at?->format('H:i'))->toBe('09:01');

    $this->travelAndWake('PT59S');
    expect(SpyStep::$calls)->toBe(1);

    $this->travelAndWake('PT1S');
    expect(SpyStep::$calls)->toBe(2)->and($instance->refresh())->toHaveFailedWith('busy');
});

// C-55
it('retries only failed instances', function (): void {
    published('flow', flow([node('wait', 'wait', ['event' => 'doc.go']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    $instance = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;

    expect(fn () => Workflows::instances()->retry($instance))->toThrow(InstanceNotRetryableException::class);

    config()->set('workflows.limits.max_starts_per_minute', 1);
    Workflows::start('flow')->dispatch();
    $refused = WorkflowInstance::query()->where('failure_code', 'start_rate_exceeded')->first() ?? throw new LogicException;

    expect(fn () => Workflows::instances()->retry($refused))->toThrow(InstanceNotRetryableException::class);
});

// C-56 (core half) + C-57
it('cancels an instance with its waits, escalations and waited children', function (): void {
    published('child', flow([node('wait', 'wait', ['event' => 'never']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    published('parent', flow([
        node('kick', 'start_workflow', ['definition' => 'child', 'wait' => true]),
        node('done', 'end'),
    ], [['start', 'kick'], ['kick', 'done', 'completed'], ['kick', 'done', 'failed'], ['kick', 'done', 'cancelled']]), null, null);

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->first() ?? throw new LogicException;

    expect($parent)->toBeWaitingAt('kick')->and($child)->toBeWaitingAt('wait');

    Workflows::instances()->cancel($parent, 'not needed');

    expect($parent->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and($child->refresh()->status)->toBe(InstanceStatus::Cancelled)
        ->and(WorkflowWait::query()->where('status', WaitStatus::Pending->value)->count())->toBe(0)
        ->and(fn () => Workflows::instances()->cancel($parent))->toThrow(InstanceNotCancellableException::class);

    // A handler that finishes after the cancel sees its result discarded.
    $run = $child->stepRuns()->where('node_id', 'wait')->first() ?? throw new LogicException;
    $run->update(['status' => StepRunStatus::Running, 'claim_token' => 'T']);
    app(RecordStepResultAction::class)->execute($child->id, $run->id, 'T', StepResult::complete('received'));

    expect($run->refresh()->status)->toBe(StepRunStatus::Cancelled)->and($child->refresh()->status)->toBe(InstanceStatus::Cancelled);
});

it('resumes a parent when its waited child is cancelled', function (): void {
    published('child', flow([node('wait', 'wait', ['event' => 'never']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]), null, null);
    published('parent', flow([
        node('kick', 'start_workflow', ['definition' => 'child', 'wait' => true]),
        node('ok', 'end'), node('stop', 'end'),
    ], [['start', 'kick'], ['kick', 'ok', 'completed'], ['kick', 'ok', 'failed'], ['kick', 'stop', 'cancelled']]), null, null);

    $parent = Workflows::start('parent')->dispatch()->instance ?? throw new LogicException;
    $child = WorkflowInstance::query()->where('parent_instance_id', $parent->id)->first() ?? throw new LogicException;

    Workflows::instances()->cancel($child);

    expect($parent->refresh())->toHaveCompletedVia(['start', 'kick', 'stop']);
});

it('cancels every running instance of a subject', function (): void {
    published('flow', flow([node('wait', 'wait', ['event' => 'never']), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]));
    $document = document();
    Workflows::start('flow', $document)->dispatch();
    Workflows::start('flow', $document)->dispatch();

    expect($document->workflows()->running())->toHaveCount(2)
        ->and($document->workflows()->cancelAll('done'))->toBe(2)
        ->and($document->workflows()->running())->toHaveCount(0)
        ->and($document->workflowInstances()->count())->toBe(2)
        ->and($document->workflows()->instances()->count())->toBe(2);
});

// W-20: an update that changes nothing a condition reads skips the probe query.
it('does not probe condition waits when no registered field changed', function (): void {
    app()->forgetInstance(SubjectRegistry::class);
    Workflows::subjects()->register(Workflows::subject('document', Document::class)
        ->field('title', 'string', 'Title')
        ->field('requester', 'party', 'Requester'));
    $document = document(null, ['title' => 'Draft']);
    $probes = static function (Closure $update): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $update();
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'workflow_waits'))->count();
        DB::disableQueryLog();

        return $count;
    };

    expect($probes(fn () => $document->update(['secret' => 'unread'])))->toBe(0)
        ->and($probes(fn () => $document->update(['title' => 'Changed'])))->toBe(1)
        ->and($probes(fn () => $document->update(['requester_id' => user()->id])))->toBe(1)
        ->and($probes(fn () => $document->update(['approver_id' => user('Bob')->id])))->toBe(0);

    Workflows::subjects()->register(Workflows::subject('memo', Memo::class)->field('author', 'party', 'Author')->field('boss', 'party', 'Boss', path: 'author.manager'));

    expect(ConditionWaits::affectedBy((new Memo)->forceFill(['id' => 1])))->toBeTrue()
        ->and(ConditionWaits::affectedBy(company()))->toBeTrue();
});

/**
 * A subject whose party field is an untyped method: never called, so it always counts.
 */
final class Memo extends Model
{
    public function author()
    {
        return null;
    }
}

// W-22
it('resumes only on an event whose payload matches', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.signed', 'where' => ['field' => 'trigger.role', 'op' => 'equals', 'value' => 'cfo']]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'received']]));
    $document = document();
    $instance = Workflows::start('flow', $document)->dispatch()->instance ?? throw new LogicException;

    expect(Workflows::emit('doc.signed', $document, ['role' => 'clerk'])->resumed)->toBe(0)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Waiting)
        ->and(Workflows::emit('doc.signed', $document, ['role' => 'cfo'])->resumed)->toBe(1)
        ->and($instance->refresh()->status)->toBe(InstanceStatus::Completed);
});

it('validates a wait payload filter', function (): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['condition' => ['field' => 'subject.title', 'op' => 'empty'], 'where' => ['field' => 'trigger.role', 'op' => 'equals', 'value' => 'cfo']]),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'done', 'met']]), 'document');

    expect(collect($report->errorsOnly())->contains(fn ($error): bool => $error->path === '/where'))->toBeTrue()
        ->and(Workflows::graph()->waitForEvent('w', 'doc.signed', where: When::field('trigger.role')->equals('cfo'))->toArray()['nodes'][0]['config'])
        ->toBe(['event' => 'doc.signed', 'where' => ['field' => 'trigger.role', 'op' => 'equals', 'value' => 'cfo']]);
});
