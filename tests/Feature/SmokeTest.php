<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Enums\InstanceStatus;
use RoundlyConsulting\Workflows\Facades\Workflows;

it('runs a manual workflow end to end', function (): void {
    $company = company();
    $document = document($company, ['amount' => '60000']);

    published('flow', graph([
        node('start', 'trigger', ['trigger' => 'manual']),
        node('big', 'condition', ['when' => ['all' => [['field' => 'subject.amount', 'op' => 'gt', 'value' => '50000']]]]),
        node('task', 'acme.create_task', ['title' => 'Review']),
        node('done', 'end'),
    ], [['start', 'big'], ['big', 'task', 'true'], ['big', 'done', 'false'], ['task', 'done']]), $company);

    $result = Workflows::start('flow', subject: $document, owner: $company)->dispatch();

    expect($result->instance?->status)->toBe(InstanceStatus::Completed)
        ->and($result->instance?->path())->toBe(['start', 'big', 'task', 'done']);
});
