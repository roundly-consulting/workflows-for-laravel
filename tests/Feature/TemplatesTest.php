<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\DataTransferObjects\ReviewFlag;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Exceptions\InvalidTemplateException;
use RoundlyConsulting\Workflows\Exceptions\NoVersionException;
use RoundlyConsulting\Workflows\Facades\Workflows;

// C-92
it('remaps every node id and rewrites references through the map', function (): void {
    $acme = company('Acme');
    published('helper', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme);
    published('review', reviewGraph(7), $acme);

    $template = Workflows::definition('review', $acme)->export();
    $result = Workflows::versions()->import($template->toArray(), $acme) ?? throw new LogicException;
    $map = $result->idMap;
    $nodes = collect($result->version->graph['nodes'])->keyBy('id');

    expect(array_keys($map))->toBe(['start', 'task', 'review', 'check', 'child', 'later', 'done'])
        ->and(array_filter($map, static fn (string $id): bool => preg_match('/^n_[a-z2-7]{10}$/', $id) !== 1))->toBe([])
        ->and($nodes[$map['check']]['config']['when']['field'])->toBe('steps.'.$map['task'].'.output.task_id')
        ->and($nodes[$map['review']]['config']['after'])->toBe($map['task'])
        ->and(collect($result->version->graph['edges'])->map(fn (array $edge): string => $edge['from'].'>'.$edge['to'])->all())
        ->toContain($map['start'].'>'.$map['task'], $map['review'].'>'.$map['check'])
        ->and($result->definition->key)->toBe('review-2')
        ->and($result->version->checksum)->not->toBe($template->checksum);
});

// C-93
it('clears and flags owner-specific values, and blocks publish until they are resolved', function (): void {
    $acme = company('Acme');
    $globex = company('Globex');
    published('helper', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme);
    published('review', reviewGraph(7), $acme);

    $template = Workflows::definition('review', $acme)->export();

    expect($template->toArray()['review'])->toBe([['node' => 'review', 'path' => '/config/reviewer', 'reason' => 'owner_specific']])
        ->and($template->toArray())->not->toHaveKey('owner')
        ->and($template->toArray())->toExposeNoClassNames();

    $result = Workflows::versions()->import($template, $globex) ?? throw new LogicException;
    $flags = collect($result->flags)->map(fn ($flag): string => $flag->path.' '.$flag->reason)->all();
    $nodes = collect($result->version->graph['nodes'])->keyBy('id');
    $review = $result->idMap['review'];

    expect($flags)->toContain('/config/reviewer owner_specific', '/config/definition definition')
        ->and($nodes[$review]['config']['reviewer'])->toBeNull()
        ->and($nodes[$result->idMap['child']]['config']['definition'])->toBeNull()
        ->and($result->definition->key)->toBe('review');

    try {
        Workflows::definition('review', $globex)->publish();
        $this->fail('Expected InvalidGraphException.');
    } catch (InvalidGraphException $exception) {
        expect($exception->validationReport()->has(ValidationCode::UnresolvedImportReference, $review))->toBeTrue();
    }

    // Editing the flagged value clears its flag; the other is dismissed.
    $graph = $result->version->graph;
    foreach ($graph['nodes'] as $index => $node) {
        if ($node['id'] === $review) {
            $graph['nodes'][$index]['config']['reviewer'] = 9;
        }
    }

    published('helper', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $globex);
    Workflows::definition('review', $globex)->edit($graph);
    Workflows::definition('review', $globex)->resolveFlag($result->idMap['child'], '/config/definition');

    expect(Workflows::definition('review', $globex)->draft()?->review_flags)->toBeNull();
});

it('flags owner calendars and user parties', function (): void {
    $template = [
        'schema' => 'roundly.workflows.template/v1',
        'definition' => ['key' => 'flow', 'name' => 'Flow', 'subject_type' => 'document', 'description' => null],
        'graph' => graph([
            node('start', 'trigger', ['trigger' => 'manual']),
            node('wait', 'wait', ['event' => 'doc.signed'], ['escalations' => [['after' => 'P1D', 'actions' => [['type' => 'reassign', 'to' => [['kind' => 'user', 'id' => 5]]]]]]]),
            node('done', 'end'),
        ], [['start', 'wait'], ['wait', 'done', 'received']]),
    ];

    $result = Workflows::versions()->import($template, company(), 'imported') ?? throw new LogicException;

    expect(collect($result->flags)->map(fn ($flag): string => $flag->path)->all())->toBe(['/escalations/0/actions/0/to/0/id'])
        ->and($result->definition->key)->toBe('imported');
});

it('refuses an invalid template', function (array $payload): void {
    Workflows::versions()->import($payload, company());
})->with([
    'schema' => [['schema' => 'nope']],
    'subject type' => [['schema' => 'roundly.workflows.template/v1', 'definition' => ['key' => 'x', 'name' => 'X', 'subject_type' => 'nope'], 'graph' => graph([], [])]],
    'graph' => [['schema' => 'roundly.workflows.template/v1', 'definition' => ['key' => 'x', 'name' => 'X'], 'graph' => ['schema' => 'nope']]],
])->throws(InvalidTemplateException::class);

it('exports a chosen version and refuses one that does not exist', function (): void {
    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]));

    expect(Workflows::definition('flow')->export(1)->key)->toBe('flow')
        ->and(fn () => Workflows::definition('flow')->export(5))->toThrow(NoVersionException::class)
        ->and(Workflows::schema()->template()['properties']['schema']['const'])->toBe('roundly.workflows.template/v1');
});

// W-04
it('carries review flags as ReviewFlag objects', function (): void {
    $acme = company('Acme');
    published('helper', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), $acme);
    published('review', reviewGraph(7), $acme);

    $template = Workflows::definition('review', $acme)->export();
    $imported = Workflows::versions()->import($template, company('Globex'));

    expect($template->review)->toHaveCount(1)->each->toBeInstanceOf(ReviewFlag::class)
        ->and($template->review[0]->is('review', '/config/reviewer'))->toBeTrue()
        ->and($imported->version->reviewFlags())->each->toBeInstanceOf(ReviewFlag::class)
        ->and(ReviewFlag::fromArray(['node' => 1]))->toBeNull()
        ->and(ReviewFlag::fromArray(['node' => 'a', 'path' => '/x'])?->reason)->toBe('owner_specific');
});
