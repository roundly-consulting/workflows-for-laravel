<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Exceptions\InvalidDiffException;
use RoundlyConsulting\Workflows\Facades\Workflows;

it('diffs two versions structurally', function (): void {
    $v1 = graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('task', 'acme.create_task', ['title' => 'one'], ['ui' => ['x' => 1]]),
        node('old', 'acme.create_task', ['title' => 'old']),
        node('done', 'end'),
    ], [['start', 'task'], ['task', 'old'], ['old', 'done']]);
    $v2 = graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('task', 'acme.create_task', ['title' => 'two'], ['ui' => ['x' => 2], 'retry' => ['max_attempts' => 2]]),
        node('check', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]),
        node('done', 'end'),
    ], [['start', 'task'], ['task', 'check'], ['check', 'done', 'true'], ['check', 'done', 'false']], ['timezone' => 'Europe/Bratislava']);

    published('flow', $v1);
    $from = Workflows::definition('flow')->model()->currentVersion;
    Workflows::definition('flow')->edit($v2);
    $to = Workflows::definition('flow')->publish();

    $diff = Workflows::versions()->diff($from ?? throw new LogicException, $to ?? throw new LogicException);

    expect($diff->identical)->toBeFalse()
        ->and($diff->nodesAdded)->toBe(['check'])
        ->and($diff->nodesRemoved)->toBe(['old'])
        ->and($diff->nodesChanged[0]->node)->toBe('task')
        ->and($diff->nodesChanged[0]->configChanges)->toBe(['/title'])
        ->and($diff->nodesChanged[0]->retryChanged)->toBeTrue()
        ->and($diff->nodesMoved)->toBe(['task'])
        ->and(array_map(static fn ($edge): array => $edge->toArray(), $diff->edgesAdded))->toContain(['from' => 'task', 'to' => 'check', 'outcome' => null])
        ->and($diff->settingsChanges)->toBe(['/timezone'])
        ->and($diff->toArray()['nodes']['added'])->toBe(['check']);
});

it('reports relabelled edges and identical versions', function (): void {
    $base = static fn (string $outcome): array => graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('check', 'condition', ['when' => ['field' => 'subject.amount', 'op' => 'gt', 'value' => '1']]),
        node('a', 'end'), node('b', 'end'),
    ], [['start', 'check'], ['check', 'a', $outcome], ['check', 'b', $outcome === 'true' ? 'false' : 'true']]);

    published('flow', $base('true'));
    $v1 = Workflows::definition('flow')->model()->currentVersion ?? throw new LogicException;
    Workflows::definition('flow')->edit($base('false'));
    $v2 = Workflows::definition('flow')->publish() ?? throw new LogicException;

    $diff = Workflows::versions()->diff($v1, $v2);

    expect(array_map(static fn ($change): array => $change->toArray(), $diff->edgesRelabelled))->toBe([
        ['from' => 'check', 'to' => 'a', 'before' => 'true', 'after' => 'false'],
        ['from' => 'check', 'to' => 'b', 'before' => 'false', 'after' => 'true'],
    ])->and(Workflows::versions()->diff($v1, $v1)->identical)->toBeTrue();
});

// C-91
it('refuses to diff versions of different definitions', function (): void {
    $graph = graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]);

    $a = published('a', $graph)->currentVersion;
    $b = published('b', $graph)->currentVersion;

    Workflows::versions()->diff($a ?? throw new LogicException, $b ?? throw new LogicException);
})->throws(InvalidDiffException::class);
