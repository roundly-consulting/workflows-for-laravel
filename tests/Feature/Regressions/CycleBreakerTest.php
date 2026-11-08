<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Exceptions\InvalidGraphException;
use RoundlyConsulting\Workflows\Facades\Workflows;

// Dual review O-11 / F-4: only a loop's `continue` edge and a positive `for` delay guard a cycle.

/**
 * @param  list<array<string, mixed>>  $nodes
 * @param  list<array{0: string, 1: string, 2?: string}>  $edges
 * @return list<ValidationCode>
 */
function cycleCodes(array $nodes, array $edges): array
{
    $report = Workflows::versions()->validate(graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'stop', 'type' => 'boolean']]]),
        node('check', 'condition', ['when' => ['field' => 'input.stop', 'op' => 'equals', 'value' => true]]),
        node('done', 'end'),
        ...$nodes,
    ], [['start', 'check'], ['check', 'done', 'true'], ...$edges]), subjectType: null);

    return array_values(array_map(static fn ($error): ValidationCode => $error->code, $report->errors));
}

it('refuses a cycle closed through a loop\'s exhausted edge', function (): void {
    expect(cycleCodes([node('again', 'loop', ['max' => 3])], [['check', 'again', 'false'], ['again', 'check', 'continue'], ['again', 'check', 'exhausted']]))
        ->toContain(ValidationCode::CycleWithoutBreaker);
});

it('accepts a cycle through a loop\'s continue edge', function (): void {
    expect(cycleCodes([node('again', 'loop', ['max' => 3])], [['check', 'again', 'false'], ['again', 'check', 'continue'], ['again', 'done', 'exhausted']]))
        ->toBe([]);
});

it('refuses a cycle whose only delay waits for nothing', function (array $config): void {
    expect(cycleCodes([node('pause', 'delay', $config)], [['check', 'pause', 'false'], ['pause', 'check']]))
        ->toContain(ValidationCode::CycleWithoutBreaker);
})->with([
    'zero ISO duration' => [['for' => 'PT0S']],
    'zero amount' => [['for' => ['amount' => 0, 'unit' => 'minutes']]],
    'a fixed instant' => [['until' => '2020-01-01T00:00:00Z']],
]);

it('accepts a cycle through a delay with a positive duration', function (array $config): void {
    expect(cycleCodes([node('pause', 'delay', $config)], [['check', 'pause', 'false'], ['pause', 'check']]))->toBe([]);
})->with([
    'ISO' => [['for' => 'PT1H']],
    'amount' => [['for' => ['amount' => 2, 'unit' => 'days']]],
]);

it('no longer runs a zero-delay cycle into the step limit', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('again', 'condition', ['when' => ['field' => 'trigger.never', 'op' => 'empty']]),
        node('pause', 'delay', ['for' => 'PT0S']),
        node('done', 'end'),
    ], [['start', 'again'], ['again', 'pause', 'true'], ['again', 'done', 'false'], ['pause', 'again']]), null, null);
})->throws(InvalidGraphException::class);
