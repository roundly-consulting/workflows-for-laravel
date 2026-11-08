<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Support\Decimals;

// W-27: `composer mutate` mutates the pure core these tests pin.
mutates(Decimals::class);

// C-17: exact decimal comparisons, no floats, no exponents.
it('compares decimals exactly', function (mixed $a, mixed $b, ?int $result): void {
    expect(Decimals::compare($a, $b))->toBe($result);
})->with([
    ['50000.00', 50000, 0],
    ['50000', '50000.0001', -1],
    ['60000', '50000', 1],
    ['-5', '3', -1],
    ['-5', '-7', 1],
    ['0.10', '0.1', 0],
    ['000123', '123', 0],
    ['-0', '0', 0],
    [99999999999999999, '99999999999999998', 1],
    [12.5, '12.50', 0],
    ['1e5', '100000', null],
    ['abc', '1', null],
    [null, '1', null],
    [INF, '1', null],
    ['10', '9.999', 1],
]);

it('normalises decimal strings', function (mixed $value, ?string $normalized): void {
    expect(Decimals::normalize($value))->toBe($normalized);
})->with([
    ['+12.500', '12.5'],
    [' 7 ', '7'],
    ['0.000', '0'],
    [true, null],
]);
