<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Facades\Workflows;

// Dual review O-9: an unlabelled edge stands only for the outcome `next`; anything else is refused at publish.

it('refuses an unlabelled edge from a node whose only outcome is not next', function (): void {
    published('child', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);

    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('sw', 'start_workflow', ['definition' => 'child']),
        node('wait', 'wait', ['event' => 'doc.go']),
        node('done', 'end'),
    ], [['start', 'sw'], ['sw', 'wait'], ['wait', 'done']]), subjectType: null);

    $errors = array_map(static fn ($error): array => [$error->code, $error->node, $error->params], $report->errors);

    expect($report->valid())->toBeFalse()
        ->and($errors)->toContain([ValidationCode::OutcomeLabelRequired, 'sw', ['outcome' => 'started']])
        ->and($errors)->toContain([ValidationCode::OutcomeLabelRequired, 'wait', ['outcome' => 'received']]);
});

it('still accepts an unlabelled edge for the outcome next', function (): void {
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('spy', 'acme.spy'),
        node('done', 'end'),
    ], [['start', 'spy'], ['spy', 'done']]), subjectType: null);

    expect($report->valid())->toBeTrue();
});
