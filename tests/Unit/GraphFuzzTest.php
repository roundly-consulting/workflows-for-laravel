<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Facades\Workflows;

// W-19: a builder posts whatever the browser sends. Validation and draft saves must answer with a
// report (or InvalidGraphException), never a TypeError or a 500 — and never echo a value back.

const FUZZ_SECRET = 'fuzz-secret-value-7f3a';

/**
 * A random value of any JSON type, nested up to `$depth`.
 */
function fuzzValue(int $depth): mixed
{
    $pick = mt_rand(0, $depth > 0 ? 11 : 7);

    return match ($pick) {
        0 => null,
        1 => (bool) mt_rand(0, 1),
        2 => mt_rand(-5, 5),
        3 => mt_rand(-1000, 1000) / 7,
        4 => ['NaN', 'INF', '-0', '1e309', '', ' ', "\0", 'ü·ž🙂'][mt_rand(0, 7)],
        5 => str_repeat('x', mt_rand(0, 3) === 0 ? 5000 : 3),
        6 => FUZZ_SECRET,
        7 => ['start', 'done', 'n1', 'Bad Id', 'trigger', 'end', 'acme.spy', 'subject.title'][mt_rand(0, 7)],
        8, 9 => array_map(static fn (): mixed => fuzzValue($depth - 1), range(0, mt_rand(0, 3))),
        default => fuzzMap($depth - 1),
    };
}

/**
 * @return array<string, mixed>
 */
function fuzzMap(int $depth): array
{
    $keys = ['id', 'type', 'config', 'from', 'to', 'outcome', 'when', 'field', 'op', 'value', 'all', 'any', 'not', 'ref', 'relative', 'trigger', 'event', 'escalations', 'after', 'actions', 'retry', 'ui', 'kind', 'stages', 'approvers', FUZZ_SECRET];
    $map = [];

    foreach (range(0, mt_rand(0, 4)) as $ignored) {
        $map[$keys[mt_rand(0, count($keys) - 1)]] = fuzzValue($depth);
    }

    return $map;
}

/**
 * A graph-shaped document with wrong types and junk at every level.
 *
 * @return array<array-key, mixed>
 */
function fuzzGraph(): array
{
    $types = ['trigger', 'end', 'condition', 'branch', 'set_field', 'delay', 'wait', 'loop', 'start_workflow', 'acme.spy', 'acme.create_task', 'approval', 'nope', 7];
    $nodes = [];

    foreach (range(0, mt_rand(0, 6)) as $index) {
        $nodes[] = mt_rand(0, 9) === 0 ? fuzzValue(2) : [
            'id' => mt_rand(0, 8) === 0 ? fuzzValue(1) : 'n'.$index,
            'type' => $types[mt_rand(0, count($types) - 1)],
            'config' => mt_rand(0, 4) === 0 ? fuzzValue(3) : fuzzMap(3),
            ...(mt_rand(0, 3) === 0 ? ['escalations' => fuzzValue(3)] : []),
            ...(mt_rand(0, 3) === 0 ? ['retry' => fuzzValue(2)] : []),
            ...(mt_rand(0, 4) === 0 ? ['ui' => fuzzValue(2)] : []),
        ];
    }

    $edges = [];

    foreach (range(0, mt_rand(0, 6)) as $ignored) {
        $edges[] = mt_rand(0, 9) === 0 ? fuzzValue(2) : ['from' => 'n'.mt_rand(0, 6), 'to' => 'n'.mt_rand(0, 6), ...(mt_rand(0, 1) === 1 ? ['outcome' => fuzzValue(0)] : [])];
    }

    $graph = [
        'schema' => mt_rand(0, 5) === 0 ? fuzzValue(1) : 'roundly.workflows.graph/v1',
        'settings' => mt_rand(0, 3) === 0 ? fuzzValue(2) : fuzzMap(1),
        'nodes' => mt_rand(0, 9) === 0 ? fuzzValue(2) : $nodes,
        'edges' => mt_rand(0, 9) === 0 ? fuzzValue(2) : $edges,
    ];

    return mt_rand(0, 19) === 0 ? array_values($graph) : $graph;
}

it('returns a report for :n random documents', function (int $n): void {
    mt_srand(20261108);
    Workflows::definitions()->create(key: 'fuzz', name: 'Fuzz', subjectType: 'document');
    $outcomes = ['report' => 0, 'refused' => 0];

    foreach (range(1, $n) as $i) {
        $graph = fuzzGraph();

        $report = Workflows::versions()->validate($graph, 'document');
        expect($report)->toBeInstanceOf(ValidationReport::class);

        try {
            Workflows::definition('fuzz')->edit($graph);
            $outcomes['report']++;
        } catch (InvalidGraphException $exception) {
            $outcomes['refused']++;
            expect($exception->validationReport())->toBeInstanceOf(ValidationReport::class);
        }
    }

    expect($outcomes['report'] + $outcomes['refused'])->toBe($n)
        ->and($outcomes['refused'])->toBeGreaterThan(0);
})->with(['n' => [500]]);

it('refuses documents over graph.max_bytes before parsing', function (): void {
    config()->set('workflows.graph.max_bytes', 1024);
    Workflows::definitions()->create(key: 'big', name: 'Big');
    $graph = graph([node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'x', 'type' => 'string', 'label' => str_repeat('y', 2000)]]]), node('done', 'end')], [['start', 'done']]);

    expect(Workflows::versions()->validate($graph)->valid())->toBeFalse()
        ->and(fn () => Workflows::definition('big')->edit($graph))->toThrow(InvalidGraphException::class);
});

it('never echoes input values into error messages', function (): void {
    mt_srand(7);

    foreach (range(1, 200) as $ignored) {
        $report = Workflows::versions()->validate(fuzzGraph(), 'document');

        foreach ($report->errors as $error) {
            expect($error->message)->not->toContain(FUZZ_SECRET);
        }

        expect(InvalidGraphException::report($report)->getMessage())->not->toContain(FUZZ_SECRET);
    }
});
