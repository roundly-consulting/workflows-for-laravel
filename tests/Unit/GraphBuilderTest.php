<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\DataTransferObjects\RetryPolicy;
use RoundlyConsulting\Workflows\Enums\ConditionOperator;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Graph\Build\After;
use RoundlyConsulting\Workflows\Graph\Build\Escalate;
use RoundlyConsulting\Workflows\Graph\Build\GraphBuilder;
use RoundlyConsulting\Workflows\Graph\Build\Input;
use RoundlyConsulting\Workflows\Graph\Build\Party;
use RoundlyConsulting\Workflows\Graph\Build\Ref;
use RoundlyConsulting\Workflows\Graph\Build\Stage;
use RoundlyConsulting\Workflows\Graph\Build\Trigger;
use RoundlyConsulting\Workflows\Graph\Build\When;
use RoundlyConsulting\Workflows\Graph\Canonicalizer;

it('builds the purchase-approval fixture exactly', function (): void {
    $graph = Workflows::graph()
        ->settings(actor: 'system', timezone: 'Europe/Bratislava', calendar: 'weekdays')
        ->trigger('start', Trigger::event('purchase_request.submitted'))
        ->approval('approve', approvers: [Party::field('subject.approver')], expiresIn: After::businessDays(5))
        ->escalate(After::businessDays(2), Escalate::reassign(Party::field('subject.approver_manager')), Escalate::notify('purchase.escalated', Party::field('subject.requester')))
        ->transition('mark_approved', 'approve')
        ->notify('tell_approved', 'purchase.approved', Party::field('subject.requester'))
        ->transition('mark_rejected', 'reject')
        ->notify('tell_rejected', 'purchase.rejected', Party::field('subject.requester'))
        ->notify('tell_expired', 'purchase.expired', [Party::field('subject.requester')])
        ->end('done')
        ->connect('start', 'approve')
        ->connect('approve', 'mark_approved', on: 'approved')
        ->connect('approve', 'mark_rejected', on: 'rejected')
        ->connect('approve', 'tell_expired', on: 'expired')
        ->connect('mark_approved', 'tell_approved', on: 'transitioned')
        ->connect('tell_approved', 'done')
        ->connect('mark_rejected', 'tell_rejected', on: 'transitioned')
        ->chain('tell_rejected', 'done')
        ->chain('tell_expired', 'done');

    expect(Canonicalizer::encode($graph->toArray()))->toBe(Canonicalizer::encode(workflowJson('purchase-approval')))
        ->and(json_decode((string) $graph, true))->toBe($graph->toArray())
        ->and($graph->jsonSerialize())->toBe($graph->toArray());
});

it('builds the README graph exactly', function (): void {
    $graph = Workflows::graph()
        ->trigger('start', Trigger::manual())
        ->condition('check', When::field('subject.amount')->gt('500'))
        ->approval('approve', approvers: [Party::field('subject.approver')])
        ->end('done')
        ->connect('start', 'check')
        ->connect('check', 'approve', on: 'true')
        ->connect('check', 'done', on: 'false')
        ->connect('approve', 'done', on: ['approved', 'rejected', 'expired']);

    expect($graph->toArray())->toBe([
        'schema' => 'roundly.workflows.graph/v1',
        'nodes' => [
            ['id' => 'start', 'type' => 'trigger', 'config' => ['trigger' => 'manual']],
            ['id' => 'check', 'type' => 'condition', 'config' => ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '500']]],
            ['id' => 'approve', 'type' => 'approval', 'config' => ['approvers' => [['kind' => 'field', 'field' => 'subject.approver']], 'rule' => 'any']],
            ['id' => 'done', 'type' => 'end'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'check'],
            ['from' => 'check', 'to' => 'approve', 'outcome' => 'true'],
            ['from' => 'check', 'to' => 'done', 'outcome' => 'false'],
            ['from' => 'approve', 'to' => 'done', 'outcome' => 'approved'],
            ['from' => 'approve', 'to' => 'done', 'outcome' => 'rejected'],
            ['from' => 'approve', 'to' => 'done', 'outcome' => 'expired'],
        ],
    ]);
});

it('round-trips every fixture graph through from()', function (string $file): void {
    $document = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $graph = is_array($document['graph'] ?? null) ? $document['graph'] : $document;

    expect(Canonicalizer::encode(GraphBuilder::from($graph)->toArray()))->toBe(Canonicalizer::encode($graph));
})->with(fn (): array => [...glob(__DIR__.'/../Fixtures/workflows/*.json') ?: [], ...glob(__DIR__.'/../Fixtures/recipes/*.json') ?: []]);

it('loads a version and keeps an empty settings block it was given', function (): void {
    $source = graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]);
    $definition = published('flow', $source, null, null);

    $builder = GraphBuilder::from($definition->currentVersion ?? throw new LogicException);

    expect(Canonicalizer::encode($builder->toArray()))->toBe(Canonicalizer::encode($source))
        ->and(GraphBuilder::from(['nodes' => ['x', ['id' => 'a', 'type' => 'end']], 'edges' => 'x'])->toArray())->toBe(['schema' => 'roundly.workflows.graph/v1', 'nodes' => [['id' => 'a', 'type' => 'end']], 'edges' => []]);
});

it('renders every condition operator', function (ConditionOperator $operator, Closure $build, array $expected): void {
    expect($build()->toArray())->toBe($expected)
        ->and($expected['op'])->toBe($operator->value);
})->with([
    'equals' => [ConditionOperator::Equals, fn () => When::field('subject.title')->equals('Laptops'), ['field' => 'subject.title', 'op' => 'equals', 'value' => 'Laptops']],
    'in' => [ConditionOperator::In, fn () => When::field('subject.department')->in(['legal', 'finance']), ['field' => 'subject.department', 'op' => 'in', 'value' => ['legal', 'finance']]],
    'gt' => [ConditionOperator::Gt, fn () => When::field('subject.amount')->gt(Ref::input('limit')), ['field' => 'subject.amount', 'op' => 'gt', 'value' => ['ref' => 'input.limit']]],
    'lt' => [ConditionOperator::Lt, fn () => When::field('subject.amount')->lt('10'), ['field' => 'subject.amount', 'op' => 'lt', 'value' => '10']],
    'between' => [ConditionOperator::Between, fn () => When::field('subject.amount')->between('10', '20'), ['field' => 'subject.amount', 'op' => 'between', 'value' => ['10', '20']]],
    'empty' => [ConditionOperator::IsEmpty, fn () => When::field('subject.approver')->isEmpty(), ['field' => 'subject.approver', 'op' => 'empty']],
    'contains' => [ConditionOperator::Contains, fn () => When::field('subject.title')->contains('lap', insensitive: true), ['field' => 'subject.title', 'op' => 'contains', 'value' => 'lap', 'case' => 'insensitive']],
    'before' => [ConditionOperator::Before, fn () => When::field('subject.due_on')->before(Ref::now()), ['field' => 'subject.due_on', 'op' => 'before', 'value' => ['ref' => 'now']]],
    'after' => [ConditionOperator::After, fn () => When::field('subject.due_on')->after(Ref::relative(After::days(-7))), ['field' => 'subject.due_on', 'op' => 'after', 'value' => ['relative' => ['amount' => -7, 'unit' => 'days']]]],
]);

it('covers every operator in the dataset above', function (): void {
    expect(ConditionOperator::count())->toBe(9);
});

it('renders durations, parties, refs, triggers and inputs', function (): void {
    expect(When::all(When::field('subject.urgent')->equals(true), When::any(When::not(When::field('subject.title')->isEmpty())))->toArray())
        ->toBe(['all' => [['field' => 'subject.urgent', 'op' => 'equals', 'value' => true], ['any' => [['not' => ['field' => 'subject.title', 'op' => 'empty']]]]]])
        ->and(array_map(static fn (After $after): array|string => $after->toValue(), [
            After::minutes(5), After::hours(2), After::days(1), After::weeks(1), After::months(1),
            After::businessMinutes(30), After::businessHours(4, 'office'), After::businessDays(2), After::iso('P2D'),
        ]))->toBe([
            ['amount' => 5, 'unit' => 'minutes'], ['amount' => 2, 'unit' => 'hours'], ['amount' => 1, 'unit' => 'days'],
            ['amount' => 1, 'unit' => 'weeks'], ['amount' => 1, 'unit' => 'months'], ['amount' => 30, 'unit' => 'business_minutes'],
            ['amount' => 4, 'unit' => 'business_hours', 'calendar' => 'office'], ['amount' => 2, 'unit' => 'business_days'], 'P2D',
        ])
        ->and(Party::list([Party::user(7), Party::user(user()), Party::user('a-uuid'), Party::role('legal'), Party::triggeredBy()]))->toBe([
            ['kind' => 'user', 'id' => 7], ['kind' => 'user', 'id' => 1], ['kind' => 'user', 'id' => 'a-uuid'], ['kind' => 'role', 'role' => 'legal'], ['kind' => 'triggered_by'],
        ])
        ->and(array_map(static fn (Ref $ref): array => $ref->toArray(), [Ref::subject('amount'), Ref::owner('plan'), Ref::input('reason'), Ref::trigger('payload.amount'), Ref::step('approve', 'request_id'), Ref::now()]))->toBe([
            ['ref' => 'subject.amount'], ['ref' => 'owner.plan'], ['ref' => 'input.reason'], ['ref' => 'trigger.payload.amount'], ['ref' => 'steps.approve.output.request_id'], ['ref' => 'now'],
        ])
        ->and(Trigger::manual(
            Input::string('title', 'Title', required: true, rules: ['max:200']),
            Input::integer('count'), Input::decimal('amount'), Input::boolean('urgent'), Input::date('due', rules: ['after:today']),
            Input::choice('priority', ['low', 'high'], 'Priority'),
        )->toArray())->toBe(['trigger' => 'manual', 'inputs' => [
            ['name' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'rules' => ['max:200']],
            ['name' => 'count', 'type' => 'integer'], ['name' => 'amount', 'type' => 'decimal'], ['name' => 'urgent', 'type' => 'boolean'],
            ['name' => 'due', 'type' => 'date', 'rules' => ['after:today']],
            ['name' => 'priority', 'type' => 'enum', 'label' => 'Priority', 'options' => ['low', 'high']],
        ]])
        ->and(Trigger::form('purchase')->toArray())->toBe(['trigger' => 'manual', 'form' => 'purchase'])
        ->and(Trigger::event('doc.signed', When::field('subject.urgent')->equals(true))->toArray())->toBe(['trigger' => 'event', 'event' => 'doc.signed', 'condition' => ['field' => 'subject.urgent', 'op' => 'equals', 'value' => true]])
        ->and(Trigger::schedule('0 9 * * 1-5', timezone: 'Europe/Bratislava')->toArray())->toBe(['trigger' => 'schedule', 'cron' => '0 9 * * 1-5', 'timezone' => 'Europe/Bratislava'])
        ->and(Trigger::schedule(preset: ['every' => 'weekday', 'at' => '09:00'])->toArray())->toBe(['trigger' => 'schedule', 'preset' => ['every' => 'weekday', 'at' => '09:00']])
        ->and(Trigger::webhook()->toArray())->toBe(['trigger' => 'webhook'])
        ->and(Trigger::webhook('header:X-Request-Id')->toArray())->toBe(['trigger' => 'webhook', 'idempotency' => ['from' => 'header:X-Request-Id']])
        ->and(Trigger::webhook(endpoint: 12)->toArray())->toBe(['trigger' => 'webhook', 'endpoint' => 12])
        ->and(Stage::named('Board')->from(Party::role('board'))->quorum(3)->toArray())->toBe(['name' => 'Board', 'approvers' => [['kind' => 'role', 'role' => 'board']], 'rule' => 'quorum', 'quorum' => 3])
        ->and(Stage::named()->from(Party::role('cfo'))->weighted(5)->toArray())->toBe(['approvers' => [['kind' => 'role', 'role' => 'cfo']], 'rule' => 'weighted', 'required' => 5])
        ->and(Stage::named('All')->from(Party::role('a'), Party::role('b'))->unanimous()->toArray()['rule'])->toBe('unanimous')
        ->and(Escalate::startWorkflow('chase')->toArray())->toBe(['type' => 'start_workflow', 'definition' => 'chase']);
});

it('writes every built-in step type', function (): void {
    $at = CarbonImmutable::parse('2026-12-01 09:00', 'UTC');
    $nodes = Workflows::graph()
        ->settings(maxSteps: 50, retry: new RetryPolicy(4, [10, 20]))
        ->branch('route', ['big' => When::field('subject.amount')->gt('100')], default: 'small')
        ->setField('mark', 'subject.priority', Ref::input('priority'))
        ->transition('approve', 'approve', lifecycle: 'purchase', reason: 'ok')
        ->delay('pause', After::hours(1))->delay('until', $at)->delay('iso', 'PT2H')
        ->delayUntilField('due', 'subject.due_on', After::days(-1))->delayUntilField('due2', 'subject.due_on')
        ->waitForEvent('signed', 'doc.signed', match: 'any', timeout: After::days(3))->waitForEvent('plain', 'doc.signed')
        ->waitUntil('ready', When::field('subject.urgent')->equals(true), poll: After::minutes(15), timeout: After::days(1))
        ->loop('again', 3)
        ->startWorkflow('child', 'helper', subject: ['field' => 'subject.requester'], input: ['reason' => 'x'], wait: true)
        ->startWorkflow('same', 'helper')
        ->approval('board', stages: [Stage::named('Board')->from(Party::role('board'))->any()], preset: 'board')
        ->notify('tell', 'doc.ready', Party::triggeredBy(), data: ['n' => 1])
        ->webhook('send', 12, 'doc.ready', ['priority' => Ref::input('priority')])->webhook('ping', 'erp', 'doc.ping')
        ->end('fail', 'failed', 'No budget')
        ->toArray();

    expect($nodes['settings'])->toBe(['limits' => ['max_steps' => 50], 'retry' => ['max_attempts' => 4, 'backoff' => [10, 20]]])
        ->and(array_column($nodes['nodes'], 'config', 'id'))->toBe([
            'route' => ['cases' => [['outcome' => 'big', 'when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '100']]], 'default' => 'small'],
            'mark' => ['field' => 'subject.priority', 'value' => ['ref' => 'input.priority']],
            'approve' => ['transition' => 'approve', 'lifecycle' => 'purchase', 'reason' => 'ok'],
            'pause' => ['for' => ['amount' => 1, 'unit' => 'hours']],
            'until' => ['until' => '2026-12-01T09:00:00+00:00'],
            'iso' => ['for' => 'PT2H'],
            'due' => ['until_field' => 'subject.due_on', 'offset' => ['amount' => -1, 'unit' => 'days']],
            'due2' => ['until_field' => 'subject.due_on'],
            'signed' => ['event' => 'doc.signed', 'match' => 'any', 'timeout' => ['amount' => 3, 'unit' => 'days']],
            'plain' => ['event' => 'doc.signed'],
            'ready' => ['condition' => ['field' => 'subject.urgent', 'op' => 'equals', 'value' => true], 'poll' => ['amount' => 15, 'unit' => 'minutes'], 'timeout' => ['amount' => 1, 'unit' => 'days']],
            'again' => ['max' => 3],
            'child' => ['definition' => 'helper', 'subject' => ['field' => 'subject.requester'], 'input' => ['reason' => 'x'], 'wait' => true],
            'same' => ['definition' => 'helper'],
            'board' => ['stages' => [['name' => 'Board', 'approvers' => [['kind' => 'role', 'role' => 'board']], 'rule' => 'any']], 'preset' => 'board'],
            'tell' => ['notification' => 'doc.ready', 'recipients' => [['kind' => 'triggered_by']], 'data' => ['n' => 1]],
            'send' => ['endpoint' => 12, 'event' => 'doc.ready', 'payload' => ['priority' => ['ref' => 'input.priority']]],
            'ping' => ['endpoint' => 'erp', 'event' => 'doc.ping'],
            'fail' => ['status' => 'failed', 'reason' => 'No budget'],
        ]);
});

it('adds one edge per outcome in connect()', function (): void {
    expect(Workflows::graph()->connect('a', 'b', on: ['x', 'y'])->connect('a', 'c')->toArray()['edges'])->toBe([
        ['from' => 'a', 'to' => 'b', 'outcome' => 'x'],
        ['from' => 'a', 'to' => 'b', 'outcome' => 'y'],
        ['from' => 'a', 'to' => 'c'],
    ]);
});

it('chains outcome-less edges', function (): void {
    expect(Workflows::graph()->chain('a', 'b', 'c')->chain('d')->toArray()['edges'])->toBe([
        ['from' => 'a', 'to' => 'b'],
        ['from' => 'b', 'to' => 'c'],
    ]);
});

it('applies retry, escalate and ui to the last node', function (): void {
    $nodes = Workflows::graph()
        ->node('task', 'acme.create_task', ['title' => 'x', 'due' => CarbonImmutable::parse('2026-01-01', 'UTC'), 'status' => InstanceStatus::Running, 'by' => user()])
        ->retry(5, [30, 60])->ui(10, 20, 'Task')
        ->approval('ok', approvers: [Party::role('legal')])
        ->retry(2)->ui(1, 2)
        ->escalate(After::days(1), Escalate::notify('x', Party::role('legal')))
        ->escalate(After::days(2), Escalate::reassign(Party::role('cfo')))
        ->toArray()['nodes'];

    expect($nodes[0])->toBe(['id' => 'task', 'type' => 'acme.create_task', 'config' => ['title' => 'x', 'due' => '2026-01-01T00:00:00+00:00', 'status' => 'running', 'by' => 1], 'retry' => ['max_attempts' => 5, 'backoff' => [30, 60]], 'ui' => ['x' => 10, 'y' => 20, 'label' => 'Task']])
        ->and($nodes[1]['retry'])->toBe(['max_attempts' => 2])
        ->and($nodes[1]['ui'])->toBe(['x' => 1, 'y' => 2])
        ->and($nodes[1]['escalations'])->toBe([
            ['after' => ['amount' => 1, 'unit' => 'days'], 'actions' => [['type' => 'notify', 'notification' => 'x', 'recipients' => [['kind' => 'role', 'role' => 'legal']]]]],
            ['after' => ['amount' => 2, 'unit' => 'days'], 'actions' => [['type' => 'reassign', 'to' => [['kind' => 'role', 'role' => 'cfo']]]]],
        ]);
});

it('refuses a modifier before any node', function (Closure $modify): void {
    expect(fn () => $modify(Workflows::graph()))->toThrow(LogicException::class, 'needs a node');
})->with([
    'retry' => [fn (GraphBuilder $graph) => $graph->retry(2)],
    'escalate' => [fn (GraphBuilder $graph) => $graph->escalate(After::days(1))],
    'ui' => [fn (GraphBuilder $graph) => $graph->ui(1, 1)],
    'after removing the last node' => [fn (GraphBuilder $graph) => $graph->end('done')->remove('done')->ui(1, 1)],
]);

it('removes a node with its edges', function (): void {
    $graph = Workflows::graph()->trigger('start', Trigger::manual())->end('a')->end('b')->chain('start', 'a', 'b')->remove('a');

    expect(array_column($graph->toArray()['nodes'], 'id'))->toBe(['start', 'b'])
        ->and($graph->toArray()['edges'])->toBe([])
        ->and($graph->ui(3, 4)->toArray()['nodes'][1]['ui'])->toBe(['x' => 3, 'y' => 4]);
});

it('keeps duplicate node ids for the validator to report', function (): void {
    $graph = Workflows::graph()->trigger('start', Trigger::manual())->end('start')->connect('start', 'start');

    expect(array_column($graph->toArray()['nodes'], 'id'))->toBe(['start', 'start'])
        ->and($graph->validate()->valid())->toBeFalse();
});

it('accepts host macros', function (): void {
    GraphBuilder::macro('task', function (string $id, string $title): GraphBuilder {
        /** @var GraphBuilder $this */
        return $this->node($id, 'acme.create_task', ['title' => $title]);
    });

    expect(Workflows::graph()->task('order', 'Order')->toArray()['nodes'])->toBe([['id' => 'order', 'type' => 'acme.create_task', 'config' => ['title' => 'Order']]]);

    GraphBuilder::flushMacros();
});
