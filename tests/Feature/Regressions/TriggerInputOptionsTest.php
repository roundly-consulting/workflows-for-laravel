<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Exceptions\InvalidTriggerInputException;
use RoundlyConsulting\Workflows\Support\TriggerInputs;

// Dual review O-14: an option holding a comma or a quote is one option.

it('accepts an option holding a comma or a quote as itself', function (): void {
    $trigger = ['inputs' => [['name' => 'client', 'type' => 'enum', 'options' => ['Smith, John', 'Doe "Jane"']]]];

    expect(TriggerInputs::validate($trigger, ['client' => 'Smith, John']))->toBe(['client' => 'Smith, John'])
        ->and(TriggerInputs::validate($trigger, ['client' => 'Doe "Jane"']))->toBe(['client' => 'Doe "Jane"']);
});

it('refuses the pieces of a comma-holding option', function (string $value): void {
    TriggerInputs::validate(['inputs' => [['name' => 'client', 'type' => 'enum', 'options' => ['Smith, John']]]], ['client' => $value]);
})->with(['Smith', ' John'])->throws(InvalidTriggerInputException::class);
