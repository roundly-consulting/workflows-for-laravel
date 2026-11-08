<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Workflows\DataTransferObjects\SideEffect;
use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Http\Resources\DryRunResultResource;
use RoundlyConsulting\Workflows\Http\Resources\OverlayResource;
use RoundlyConsulting\Workflows\Models\WorkflowDryRun;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Steps\StepResult;

/**
 * @param  array<string, mixed>  $trigger
 */
function dryFlow(array $trigger = [], array $settings = []): array
{
    return graph([
        node('start', 'trigger', ['trigger' => 'manual', ...$trigger]),
        node('pick', 'branch', ['cases' => [['outcome' => 'big', 'when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '100']]], 'default' => 'small']),
        node('mark', 'set_field', ['field' => 'subject.title', 'value' => 'Big']),
        node('pause', 'delay', ['for' => 'PT2H']),
        node('task', 'acme.spy'),
        node('wait', 'wait', ['event' => 'doc.go'], ['escalations' => [['after' => 'PT1H', 'actions' => [['type' => 'start_workflow', 'definition' => 'child']]]]]),
        node('done', 'end'),
        node('skip', 'end'),
    ], [['start', 'pick'], ['pick', 'mark', 'big'], ['pick', 'skip', 'small'], ['mark', 'pause'], ['pause', 'task'], ['task', 'wait'], ['wait', 'done', 'received']], $settings);
}

beforeEach(function (): void {
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
});

it('walks the path without writing, simulating, timing and assuming outcomes', function (): void {
    published('flow', dryFlow());
    $document = document(null, ['amount' => '500']);
    $requester = user();
    $writes = 0;
    DB::listen(static function ($query) use (&$writes): void {
        $writes += preg_match('/^\s*(insert|update|delete)/i', $query->sql);
    });
    $at = CarbonImmutable::parse('2026-11-02 09:00', 'UTC');

    $result = Workflows::definition('flow')->dryRun()->subject($document)->at($at)->by($requester)->run();

    expect($writes)->toBe(0)
        ->and($result->valid)->toBeTrue()
        ->and($result->started)->toBeTrue()
        ->and($result->nodes())->toBe(['start', 'pick', 'mark', 'pause', 'task', 'wait', 'done'])
        ->and($result->endedAt)->toBe('done')
        ->and($result->endedBy)->toBe('end')
        ->and($result->path[2]->at)->toBe($at->toIso8601String())
        ->and($result->path[3]->at)->toBe($at->addHours(2)->toIso8601String())
        ->and($result->path[5]->assumed)->toBeTrue()
        ->and(collect($result->actions)->map(fn ($action): string => $action->node.':'.$action->type.':'.$action->status)->all())->toBe([
            'mark:set_field:simulated', 'task:acme.spy:would_run', 'wait:escalation:would_run',
        ])
        ->and($result->actions[2]->details['due_at'])->toBe($at->addHours(3)->toIso8601String())
        ->and($document->refresh()->title)->toBe('Doc')
        ->and(WorkflowInstance::query()->count())->toBe(0)
        ->and(WorkflowDryRun::query()->count())->toBe(0)
        ->and((new DryRunResultResource($result))->resolve())->toMatchArray(['valid' => true, 'ended_by' => 'end']);
});

it('walks a sample subject and assumed outcomes', function (): void {
    published('flow', dryFlow());

    $small = Workflows::definition('flow')->dryRun()->sample(['amount' => '5', 'nope' => 1])->run();
    $assumed = Workflows::definition('flow')->dryRun()->sample(['amount' => '500'])->assume(['pick' => 'small'])->run();

    expect($small->nodes())->toBe(['start', 'pick', 'skip'])
        ->and($assumed->nodes())->toBe(['start', 'pick', 'skip'])
        ->and($assumed->path[1]->assumed)->toBeTrue();
});

// C-89
it('logs a dry run as its only write', function (): void {
    published('flow', dryFlow());
    $requester = user();

    Workflows::definition('flow')->dryRun()->sample(['amount' => '5'])->log()->by($requester)->run();

    $row = WorkflowDryRun::query()->sole();

    expect($row->result['ended_by'])->toBe('end')
        ->and($row->requester_id)->toBe($requester->id)
        ->and($row->definition?->key)->toBe('flow')
        ->and($row->requester?->is($requester))->toBeTrue();

    config()->set('workflows.dry_run.log', true);
    Workflows::definition('flow')->dryRun()->subject(document())->run();
    Workflows::definition('flow')->dryRun()->log(false)->run();

    expect(WorkflowDryRun::query()->count())->toBe(2);
});

it('reports why a dry run would not start', function (array $trigger, array $input, string $reason): void {
    published('flow', dryFlow($trigger));

    $result = Workflows::definition('flow')->dryRun()->sample(['amount' => '5'])->input($input)->run();

    expect($result->started)->toBeFalse()->and($result->reason)->toBe($reason)->and($result->endedBy)->toBe('not_started');
})->with([
    'invalid input' => [['inputs' => [['name' => 'reason', 'type' => 'string', 'required' => true]]], [], 'invalid_input'],
    'condition false' => [['inputs' => [['name' => 'go', 'type' => 'boolean']], 'condition' => ['field' => 'input.go', 'op' => 'equals', 'value' => true]], ['go' => false], 'condition_false'],
]);

it('refuses a subject of another owner', function (): void {
    $acme = company('Acme');
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme, null);
    published('flow', dryFlow(), $acme);

    $result = Workflows::definition('flow', $acme)->dryRun()->subject(document(company('Globex')))->run();

    expect($result->reason)->toBe('owner_mismatch');
});

it('dry-runs a draft graph, an invalid graph and an event trigger', function (): void {
    published('flow', dryFlow());
    $definition = Workflows::definition('flow')->model();

    $invalid = Workflows::versions()->dryRun(graph([node('start', 'trigger', ['trigger' => 'manual'])], []), $definition)->run();
    $event = Workflows::versions()->dryRun(graph([
        node('start', 'trigger', ['trigger' => 'event', 'event' => 'doc.go']), node('done', 'end'),
    ], [['start', 'done']]), $definition)->event('doc.go', ['a' => 1])->run();
    $version = Workflows::versions()->dryRun(Workflows::definition('flow')->version(1) ?? throw new LogicException)->sample(['amount' => '1'])->run();

    expect($invalid->valid)->toBeFalse()->and($invalid->reason)->toBe('invalid_graph')
        ->and($event->nodes())->toBe(['start', 'done'])
        ->and($version->nodes())->toBe(['start', 'pick', 'skip'])
        ->and(Workflows::definition('flow')->dryRun(1)->sample(['amount' => '1'])->run()->nodes())->toBe(['start', 'pick', 'skip']);
});

// C-94
it('counts the in-flight instances per node and status', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('wait', 'wait', ['event' => 'doc.go']),
        node('spy', 'acme.spy', ['mode' => 'fail']),
        node('done', 'end'),
    ], [['start', 'wait'], ['wait', 'spy', 'received'], ['spy', 'done']]), null, null);

    $a = Workflows::start('flow')->dispatch()->instance ?? throw new LogicException;
    Workflows::start('flow')->dispatch();
    Workflows::start('flow')->dispatch();
    Workflows::instances()->cancel($a);
    Workflows::emit('doc.go');
    Workflows::start('flow')->dispatch();

    $version = Workflows::definition('flow')->model()->currentVersion ?? throw new LogicException;
    $overlay = Workflows::versions()->overlay($version);

    expect($overlay->total)->toBe(3)
        ->and($overlay->node('wait')?->toArray())->toBe(['node' => 'wait', 'running' => 0, 'waiting' => 1, 'failed' => 0])
        ->and($overlay->node('spy')?->failed)->toBe(2)
        ->and($overlay->node('done'))->toBeNull()
        ->and((new OverlayResource($overlay))->resolve())->toBe($overlay->toArray())
        ->and(WorkflowInstance::query()->where('status', InstanceStatus::Cancelled->value)->count())->toBe(1);
});

// W-04
it('collects would-do side effects as SideEffect objects', function (): void {
    $result = StepResult::complete()->wouldDo('Create task', ['title' => 'Order'])->wouldDo('Notify');

    expect($result->wouldDo)->toHaveCount(2)->each->toBeInstanceOf(SideEffect::class)
        ->and($result->wouldDo[0]->details)->toBe(['title' => 'Order'])
        ->and($result->wouldDo[1]->description)->toBe('Notify');
});

// W-03
it('shows each simulated step\'s input', function (): void {
    $definition = published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'title', 'type' => 'string']]]),
        node('task', 'acme.create_task', ['title' => 'Order']),
        node('mark', 'set_field', ['field' => 'subject.title', 'value' => ['ref' => 'input.title']]),
        node('done', 'end'),
    ], [['start', 'task'], ['task', 'mark'], ['mark', 'done']]));

    $path = Workflows::definition('flow')->dryRun()->subject(document())->input(['title' => 'Renamed'])->run()->path;

    expect($path[0]->input)->toBe(['input' => ['title' => 'Renamed'], 'trigger' => []])
        ->and($path[1]->input)->toBe(['title' => 'Order'])
        ->and($path[2]->input)->toBe(['field' => 'subject.title', 'value' => 'Renamed'])
        ->and($path[2]->toArray()['input'])->toBe(['field' => 'subject.title', 'value' => 'Renamed'])
        ->and($definition->key)->toBe('flow');
});
