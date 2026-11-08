<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\Conditions\Evaluator;
use RoundlyConsulting\Workflows\Support\FieldReader;
use RoundlyConsulting\Workflows\Support\SubjectRegistry;
use RoundlyConsulting\Workflows\Support\ValueScope;

// Dual review O-12 / F-2: a `date` field compares by its stored calendar date in any timezone.

/**
 * @return array{equals: bool, before: bool, after: bool}
 */
function dueOnComparisons(string $timezone, string $stored = '2026-11-10 00:00:00', bool $dateCast = false): array
{
    $doc = document(null, ['due_on' => $stored]);

    if ($dateCast) {
        $doc->mergeCasts(['due_on' => 'date']);
    }

    $scope = new ValueScope($doc, app(SubjectRegistry::class)->find('document'), null, null, [], [], static fn (): ?array => null, CarbonImmutable::now(), $timezone);

    return [
        'equals' => Evaluator::evaluate(['field' => 'subject.due_on', 'op' => 'equals', 'value' => '2026-11-10'], $scope),
        'before' => Evaluator::evaluate(['field' => 'subject.due_on', 'op' => 'before', 'value' => '2026-11-10'], $scope),
        'after' => Evaluator::evaluate(['field' => 'subject.due_on', 'op' => 'after', 'value' => '2026-11-09'], $scope),
    ];
}

it('reads a date field on its stored calendar day', function (string $timezone, bool $dateCast): void {
    expect(dueOnComparisons($timezone, dateCast: $dateCast))->toBe(['equals' => true, 'before' => false, 'after' => true]);
})->with([
    'New York, datetime cast' => ['America/New_York', false],
    'New York, date cast' => ['America/New_York', true],
    'Los Angeles' => ['America/Los_Angeles', true],
    'Tokyo' => ['Asia/Tokyo', true],
    'UTC' => ['UTC', true],
]);

it('reads a date field on its stored day when the app timezone is not UTC', function (): void {
    config()->set('app.timezone', 'Asia/Tokyo');
    date_default_timezone_set('Asia/Tokyo');

    try {
        expect(dueOnComparisons('America/New_York', dateCast: true))->toBe(['equals' => true, 'before' => false, 'after' => true]);
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('takes the matching branch for a date field in a western timezone', function (): void {
    $document = document(null, ['due_on' => '2026-01-05 00:00:00']);

    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('check', 'condition', ['when' => ['field' => 'subject.due_on', 'op' => 'equals', 'value' => '2026-01-05']]),
        node('yes', 'end'), node('no', 'end'),
    ], [['start', 'check'], ['check', 'yes', 'true'], ['check', 'no', 'false']], ['timezone' => 'America/New_York']));

    $instance = Workflows::start('flow', subject: $document)->dispatch()->instance ?? throw new LogicException;

    expect($instance->refresh()->current_node_id)->toBe('yes');
});

it('delays until a date field at midnight in the version timezone', function (): void {
    Carbon::setTestNow('2026-01-01 12:00:00');
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    $document = document(null, ['due_on' => '2026-01-05 00:00:00']);

    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('pause', 'delay', ['until_field' => 'subject.due_on']),
        node('done', 'end'),
    ], [['start', 'pause'], ['pause', 'done']], ['timezone' => 'America/New_York']));

    Workflows::start('flow', subject: $document)->dispatch();

    expect(WorkflowWait::query()->sole()->due_at?->toDateTimeString())->toBe('2026-01-05 05:00:00');
});

it('compares a date trigger input by its calendar day', function (): void {
    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual', 'inputs' => [['name' => 'on', 'type' => 'date']]]),
        node('check', 'condition', ['when' => ['field' => 'input.on', 'op' => 'equals', 'value' => '2026-01-05']]),
        node('yes', 'end'), node('no', 'end'),
    ], [['start', 'check'], ['check', 'yes', 'true'], ['check', 'no', 'false']], ['timezone' => 'America/New_York']), null, null);

    $instance = Workflows::start('flow')->input(['on' => '2026-01-05'])->dispatch()->instance ?? throw new LogicException;

    expect($instance->refresh()->current_node_id)->toBe('yes');
});

it('reads a date field holding a time of day as the day of that instant', function (): void {
    expect(dueOnComparisons('America/New_York', '2026-11-11 03:00:00'))->toBe(['equals' => true, 'before' => false, 'after' => true])
        ->and(FieldReader::calendarDate('2026-11-10', 'America/New_York')?->toIso8601String())->toBe('2026-11-10T00:00:00-05:00')
        ->and(FieldReader::calendarDate('2026-11-10T00:00:00', 'Asia/Tokyo')?->toIso8601String())->toBe('2026-11-10T00:00:00+09:00')
        ->and(FieldReader::calendarDate('not a date', 'UTC'))->toBeNull();
});
