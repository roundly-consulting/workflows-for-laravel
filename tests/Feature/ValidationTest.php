<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Enums\Severity;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Bridges;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

function inspectGraph(array $graph, ?string $subjectType = 'document', $owner = null): ValidationReport
{
    return Workflows::versions()->validate($graph, $subjectType, $owner);
}

/**
 * @return list<string> "code@node" pairs
 */
function codes(ValidationReport $report): array
{
    return array_map(static fn (ValidationError $error): string => $error->code->value.($error->node === null ? '' : '@'.$error->node), $report->errors);
}

function start(array $config = ['trigger' => 'manual']): array
{
    return node('start', 'trigger', $config);
}

it('accepts a valid graph', function (): void {
    $report = inspectGraph(graph([start(), node('done', 'end')], [['start', 'done']]));

    expect($report->valid())->toBeTrue()->and($report->errors)->toBe([])->and($report->toArray()['valid'])->toBeTrue();
});

it('stops at structural errors', function (array $graph, string $code): void {
    $report = inspectGraph($graph);

    expect($report->valid())->toBeFalse()->and($report->errors[0]->code->value)->toBe($code);
})->with([
    'schema version' => [['schema' => 'v0', 'nodes' => [], 'edges' => []], 'invalid_schema_version'],
    'nodes not a list' => [['schema' => 'roundly.workflows.graph/v1', 'nodes' => 'x', 'edges' => []], 'invalid_structure'],
    'edges not a list' => [['schema' => 'roundly.workflows.graph/v1', 'nodes' => [], 'edges' => ['a' => 1]], 'invalid_structure'],
    'bad node id' => [graph([['id' => 'Start', 'type' => 'trigger']], []), 'invalid_node_id'],
    'node not an object' => [graph(['x'], []), 'invalid_structure'],
    'duplicate node' => [graph([start(), start()], []), 'duplicate_node'],
    'type missing' => [graph([['id' => 'a']], []), 'invalid_structure'],
    'config not an object' => [graph([['id' => 'a', 'type' => 'end', 'config' => 'x']], []), 'invalid_structure'],
    'escalations not a list' => [graph([['id' => 'a', 'type' => 'end', 'escalations' => ['x' => 1]]], []), 'invalid_structure'],
    'dangling edge' => [graph([start()], [['start', 'nowhere']]), 'dangling_edge'],
    'bad edge' => [['schema' => 'roundly.workflows.graph/v1', 'nodes' => [start()], 'edges' => [['from' => 'start']]], 'invalid_structure'],
    'settings not an object' => [['schema' => 'roundly.workflows.graph/v1', 'settings' => 'x', 'nodes' => [], 'edges' => []], 'invalid_structure'],
]);

// C-01
it('refuses an oversized graph', function (): void {
    config()->set('workflows.graph.max_nodes', 2);

    expect(codes(inspectGraph(graph([start(), node('a', 'end'), node('b', 'end')], []))))->toBe(['graph_too_large']);
});

it('reports unknown types, trigger counts and misplaced edges', function (): void {
    $report = inspectGraph(graph([
        start(), node('again', 'trigger', ['trigger' => 'manual']), node('odd', 'acme.nope'), node('done', 'end'),
    ], [['start', 'done'], ['again', 'done'], ['done', 'start']]));

    expect(codes($report))->toContain('unknown_node_type@odd', 'trigger_count@again', 'trigger_has_incoming@start', 'end_has_outgoing@done');
});

it('reports a missing end', function (): void {
    expect(codes(inspectGraph(graph([start(), node('task', 'acme.create_task', ['title' => 'x'])], [['start', 'task']]))))->toContain('no_end');
});

it('reports adapters whose package is missing', function (): void {
    $report = inspectGraph(graph([start(), node('approve', 'approval', []), node('go', 'transition', ['transition' => 'x']), node('done', 'end')], [['start', 'approve'], ['approve', 'go', 'approved'], ['go', 'done', 'transitioned']]));

    expect(codes($report))->toContain('adapter_missing@approve', 'adapter_missing@go')
        ->and($report->find(ValidationCode::AdapterMissing, 'approve')?->params['package'])->toBe('roundly-consulting/approvals-for-laravel');
});

it('validates node config against the type schema, naming the path', function (): void {
    $report = inspectGraph(graph([start(), node('task', 'acme.create_task', ['title' => 7, 'extra' => 1]), node('done', 'end')], [['start', 'task'], ['task', 'done']]));
    $error = $report->find(ValidationCode::InvalidConfig, 'task');

    expect($error?->path)->toBe('/title')
        ->and($error?->params)->toBe(['keyword' => 'type', 'expected' => 'string'])
        ->and(codes($report))->toContain('invalid_config@task');
});

it('checks outcome edges', function (): void {
    $report = inspectGraph(graph([
        start(),
        node('check', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]),
        node('done', 'end'),
    ], [['start', 'check'], ['check', 'done', 'true'], ['check', 'done', 'true'], ['check', 'done', 'maybe']]));

    expect(codes($report))->toContain('missing_outcome@check', 'duplicate_outcome@check', 'unknown_outcome@check')
        ->and($report->find(ValidationCode::MissingOutcome, 'check')?->params)->toBe(['outcome' => 'false']);
});

it('accepts a single-outcome edge labelled with its outcome', function (): void {
    expect(inspectGraph(graph([start(), node('done', 'end')], [['start', 'done', 'next']]))->valid())->toBeTrue();
});

it('reports unreachable nodes one by one', function (): void {
    $report = inspectGraph(graph([start(), node('done', 'end'), node('orphan', 'acme.create_task', ['title' => 'x']), node('island', 'end')], [['start', 'done'], ['orphan', 'island']]));

    expect(codes($report))->toContain('unreachable_node@orphan', 'unreachable_node@island');
});

// A-10 + C-31
it('rejects a cycle without a delay or a loop counter', function (): void {
    $report = inspectGraph(graph([
        start(), node('a', 'acme.create_task', ['title' => 'a']), node('b', 'acme.create_task', ['title' => 'b']),
        node('check', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]), node('done', 'end'),
    ], [['start', 'a'], ['a', 'b'], ['b', 'check'], ['check', 'a', 'true'], ['check', 'done', 'false']]));

    $error = $report->find(ValidationCode::CycleWithoutBreaker);

    expect($error?->node)->toBe('a')->and($error?->params['nodes'])->toBe(['a', 'b', 'check']);
});

it('accepts a cycle with a delay', function (): void {
    expect(inspectGraph(graph([
        start(), node('a', 'acme.create_task', ['title' => 'a']), node('wait', 'delay', ['for' => 'PT1H']),
        node('check', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]), node('done', 'end'),
    ], [['start', 'a'], ['a', 'wait'], ['wait', 'check'], ['check', 'a', 'true'], ['check', 'done', 'false']]))->valid())->toBeTrue();
});

it('accepts a cycle with a loop counter', function (): void {
    expect(inspectGraph(graph([
        start(), node('a', 'acme.create_task', ['title' => 'a']), node('again', 'loop', ['max' => 3]), node('done', 'end'),
    ], [['start', 'a'], ['a', 'again'], ['again', 'a', 'continue'], ['again', 'done', 'exhausted']]))->valid())->toBeTrue();
});

it('rejects an unguarded cycle inside a guarded component', function (): void {
    // a → b → a is unguarded; a → loop → a is guarded. One SCC, still rejected.
    $report = inspectGraph(graph([
        start(), node('a', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]),
        node('b', 'acme.create_task', ['title' => 'b']), node('again', 'loop', ['max' => 2]), node('done', 'end'),
    ], [['start', 'a'], ['a', 'b', 'true'], ['b', 'a'], ['a', 'again', 'false'], ['again', 'a', 'continue'], ['again', 'done', 'exhausted']]));

    expect($report->find(ValidationCode::CycleWithoutBreaker)?->params['nodes'])->toBe(['a', 'b']);
});

it('validates the loop limit, branch outcomes and delay modes', function (): void {
    config()->set('workflows.limits.max_loop_iterations', 5);

    $report = inspectGraph(graph([
        start(),
        node('again', 'loop', ['max' => 6]),
        node('pick', 'branch', ['cases' => [['outcome' => 'big', 'when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']], ['outcome' => 'big', 'when' => ['field' => 'subject.amount', 'op' => 'lt', 'value' => '1']]], 'default' => 'big']),
        node('wait', 'delay', ['for' => 'PT1H', 'until' => '2026-12-01T00:00:00Z']),
        node('pause', 'delay', ['offset' => 'P1D', 'for' => 'P1D']),
        node('later', 'delay', ['until_field' => 'subject.title']),
        node('back', 'delay', ['for' => '-P1D']),
        node('done', 'end'),
    ], [['start', 'again'], ['again', 'pick', 'continue'], ['again', 'done', 'exhausted'], ['pick', 'wait', 'big'], ['wait', 'pause'], ['pause', 'later'], ['later', 'back'], ['back', 'done']]));

    expect(codes($report))->toContain('invalid_config@again', 'invalid_config@pick', 'invalid_config@wait', 'invalid_config@pause', 'invalid_value@later', 'invalid_config@back');
});

// C-95
it('needs unique, well-formed branch outcomes', function (): void {
    $report = inspectGraph(graph([
        start(),
        node('pick', 'branch', ['cases' => [['outcome' => 'Big', 'when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]], 'default' => 'other']),
        node('done', 'end'),
    ], [['start', 'pick'], ['pick', 'done', 'other']]));

    expect($report->find(ValidationCode::InvalidConfig, 'pick')?->path)->toBe('/cases/0/outcome');
});

// C-71
it('lets graph settings lower the limits but never raise them', function (): void {
    $base = [start(), node('done', 'end')];

    expect(codes(inspectGraph(graph($base, [['start', 'done']], ['limits' => ['max_steps' => 500]]))))->toBe(['invalid_config'])
        ->and(inspectGraph(graph($base, [['start', 'done']], ['limits' => ['max_steps' => 50]]))->valid())->toBeTrue();
});

it('validates the graph settings', function (): void {
    $report = inspectGraph(graph([start(), node('done', 'end')], [['start', 'done']], [
        'actor' => 'admin', 'timezone' => 'Mars/Base', 'calendar' => 'opening_hours:main', 'retry' => ['max_attempts' => 99],
    ]));

    expect(array_map(static fn (ValidationError $error): string => $error->code->value.' '.$error->path, $report->errors))->toBe([
        'invalid_config /settings/actor',
        'invalid_config /settings/timezone',
        'unknown_calendar /settings/calendar',
        'invalid_config /settings/retry',
    ]);
});

it('requires a triggering user for on_behalf_of only where one exists', function (): void {
    $schedule = graph([start(['trigger' => 'schedule', 'cron' => '0 9 * * *']), node('done', 'end')], [['start', 'done']], ['actor' => 'on_behalf_of']);

    expect(codes(inspectGraph($schedule, null)))->toBe(['actor_requires_user@start']);
});

it('validates trigger configs', function (array $config, string $code): void {
    expect(codes(inspectGraph(graph([start($config), node('done', 'end')], [['start', 'done']]), null)))->toContain($code.'@start');
})->with([
    'event key missing' => [['trigger' => 'event'], 'invalid_config'],
    'event key pattern' => [['trigger' => 'event', 'event' => 'Bad Key'], 'invalid_config'],
    'webhook key' => [['trigger' => 'webhook'], 'invalid_config'],
    'cron' => [['trigger' => 'schedule', 'cron' => '0 9 L * *'], 'invalid_config'],
    'cron or preset' => [['trigger' => 'schedule'], 'invalid_cron'],
    'preset' => [['trigger' => 'schedule', 'preset' => ['every' => 'minutes']], 'invalid_cron'],
    'duplicate inputs' => [['trigger' => 'manual', 'inputs' => [['name' => 'a', 'type' => 'string'], ['name' => 'a', 'type' => 'string']]], 'invalid_config'],
    'refused rule' => [['trigger' => 'manual', 'inputs' => [['name' => 'a', 'type' => 'string', 'rules' => ['regex:/x/']]]], 'invalid_config'],
    'trigger condition' => [['trigger' => 'manual', 'condition' => ['field' => 'input.nope', 'op' => 'empty']], 'unknown_input'],
]);

// C-138: an owned definition over a subject type whose owner cannot be resolved.
it('reports an owned definition whose subject owner cannot be resolved', function (): void {
    Workflows::subjects()->register(Workflows::subject('person', User::class)->field('name', 'string'));

    $report = inspectGraph(graph([start(), node('done', 'end')], [['start', 'done']]), 'person', company());

    expect(codes($report))->toBe(['subject_owner_unresolvable@start'])
        ->and(inspectGraph(graph([start(), node('done', 'end')], [['start', 'done']]), 'person')->valid())->toBeTrue();
});

it('reports an unknown subject type', function (): void {
    expect(codes(inspectGraph(graph([start(), node('done', 'end')], [['start', 'done']]), 'nope')))->toBe(['unknown_subject_type']);
});

it('validates set_field targets', function (array $config, string $code): void {
    expect(codes(inspectGraph(graph([start(), node('set', 'set_field', $config), node('done', 'end')], [['start', 'set'], ['set', 'done']]))))->toContain($code.'@set');
})->with([
    'not writable' => [['field' => 'subject.amount', 'value' => '1'], 'field_not_writable'],
    'relation path' => [['field' => 'subject.approver_manager', 'value' => 1], 'field_not_writable'],
    'not a subject field' => [['field' => 'input.x', 'value' => 1], 'field_not_writable'],
    'unknown' => [['field' => 'subject.nope', 'value' => 1], 'unknown_field'],
    'wrong value' => [['field' => 'subject.priority', 'value' => ['a' => 1]], 'invalid_value'],
    'bad ref' => [['field' => 'subject.priority', 'value' => ['ref' => 'subject.nope']], 'unknown_field'],
]);

it('validates escalation blocks', function (array $escalations, string $code): void {
    $report = inspectGraph(graph([start(), node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => $escalations]), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]));

    expect(codes($report))->toContain($code.'@wait');
})->with([
    'bad after' => [[['after' => 'soon', 'actions' => [['type' => 'start_workflow', 'definition' => 'x']]]], 'invalid_config'],
    'no actions' => [[['after' => 'P1D', 'actions' => []]], 'invalid_config'],
    'unknown action' => [[['after' => 'P1D', 'actions' => [['type' => 'email']]]], 'invalid_config'],
    'reassign unsupported' => [[['after' => 'P1D', 'actions' => [['type' => 'reassign', 'to' => [['kind' => 'triggered_by']]]]]], 'invalid_config'],
    'notify without the package' => [[['after' => 'P1D', 'actions' => [['type' => 'notify']]]], 'adapter_missing'],
    'unknown target' => [[['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'nope']]]], 'unknown_definition'],
    'unknown calendar' => [[['after' => ['amount' => 1, 'unit' => 'business_days', 'calendar' => 'nope'], 'actions' => [['type' => 'start_workflow', 'definition' => 'x']]]], 'unknown_calendar'],
]);

it('refuses escalations on a type without them, and too many levels', function (): void {
    config()->set('workflows.escalations.max_levels', 1);
    $level = ['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'x']]];

    expect(codes(inspectGraph(graph([start(), node('task', 'acme.create_task', ['title' => 'x'], ['escalations' => [$level]]), node('done', 'end')], [['start', 'task'], ['task', 'done']]))))->toContain('invalid_config@task')
        ->and(codes(inspectGraph(graph([start(), node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [$level, $level]]), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]))))->toContain('invalid_config@wait');
});

it('reports roles that cannot be resolved without a role resolver', function (): void {
    Bridges::pretendMissing();

    $report = inspectGraph(graph([start(), node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [['after' => 'P1D', 'actions' => [['type' => 'start_workflow', 'definition' => 'x']]]]]), node('done', 'end')], [['start', 'wait'], ['wait', 'done', 'received']]));

    expect($report->has(ValidationCode::UnknownDefinition))->toBeTrue();
});

it('separates errors from warnings', function (): void {
    $report = new ValidationReport([
        ValidationError::make(ValidationCode::SelfStart, node: 'a', severity: Severity::Warning),
    ]);

    expect($report->valid())->toBeTrue()->and($report->warnings())->toHaveCount(1)->and($report->errorsOnly())->toBe([]);
});

it('renders messages without class names', function (): void {
    $report = inspectGraph(graph([start(), node('odd', 'acme.nope'), node('done', 'end')], [['start', 'odd'], ['odd', 'done']]));

    expect($report->find(ValidationCode::UnknownNodeType, 'odd')?->message)->toBe('The node "odd" has an unknown type "acme.nope".')
        ->and(json_encode($report->toArray()))->not->toContain('\\\\');
});
