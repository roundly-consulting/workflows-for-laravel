<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Exceptions\InvalidStepTypeException;
use RoundlyConsulting\Workflows\Support\Schema\SchemaError;
use RoundlyConsulting\Workflows\Support\Schema\SchemaValidator;

/**
 * @param  array<string, mixed>  $schema
 * @return list<string> "path keyword" pairs
 */
function schemaErrors(array $schema, mixed $value): array
{
    return array_map(static fn (SchemaError $error): string => trim($error->path.' '.$error->keyword), SchemaValidator::validate($schema, $value));
}

it('validates the supported keywords', function (array $schema, mixed $value, array $errors): void {
    expect(schemaErrors($schema, $value))->toBe($errors);
})->with([
    'type ok' => [['type' => 'string'], 'x', []],
    'type list' => [['type' => ['integer', 'null']], null, []],
    'type wrong' => [['type' => 'integer'], 'x', ['type']],
    'number' => [['type' => 'number'], 1.5, []],
    'boolean' => [['type' => 'boolean'], 'yes', ['type']],
    'object' => [['type' => 'object'], ['a' => 1], []],
    'empty object' => [['type' => 'object'], [], []],
    'array' => [['type' => 'array'], [1, 2], []],
    'enum' => [['enum' => ['a', 'b']], 'c', ['enum']],
    'const' => [['const' => 'same'], 'other', ['const']],
    'required' => [['type' => 'object', 'required' => ['a']], ['b' => 1], ['/a required']],
    'additional false' => [['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false], ['a' => 1, 'b' => 2], ['/b additionalProperties']],
    'additional schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']], ['b' => 2], ['/b type']],
    'nested property' => [['type' => 'object', 'properties' => ['a' => ['type' => 'integer', 'minimum' => 5]]], ['a' => 1], ['/a minimum']],
    'items' => [['type' => 'array', 'items' => ['type' => 'integer']], [1, 'x'], ['/1 type']],
    'min/max items' => [['type' => 'array', 'minItems' => 2, 'maxItems' => 3], [1], ['minItems']],
    'max items' => [['type' => 'array', 'maxItems' => 1], [1, 2], ['maxItems']],
    'unique' => [['type' => 'array', 'uniqueItems' => true], [1, 1], ['uniqueItems']],
    'length' => [['type' => 'string', 'minLength' => 2, 'maxLength' => 3], 'x', ['minLength']],
    'max length' => [['type' => 'string', 'maxLength' => 3], 'xxxx', ['maxLength']],
    'bounds' => [['type' => 'integer', 'maximum' => 5, 'exclusiveMinimum' => 1], 1, ['exclusiveMinimum']],
    'exclusive max' => [['type' => 'integer', 'exclusiveMaximum' => 5], 5, ['exclusiveMaximum']],
    'maximum' => [['type' => 'integer', 'maximum' => 5], 6, ['maximum']],
    'multipleOf' => [['type' => 'integer', 'multipleOf' => 5], 12, ['multipleOf']],
    'pattern' => [['type' => 'string', 'pattern' => '^[a-z]+$'], 'ABC', ['pattern']],
    'pattern ok' => [['type' => 'string', 'pattern' => '^[a-z]+$'], 'abc', []],
    'oneOf' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]], true, ['oneOf']],
    'anyOf' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]], 1, []],
    'allOf' => [['allOf' => [['type' => 'integer'], ['minimum' => 3]]], 1, ['minimum']],
    'ref' => [['$ref' => '#/$defs/party'], ['kind' => 'nobody'], ['/kind enum']],
    'local defs' => [['$defs' => ['x' => ['type' => 'string']], '$ref' => '#/$defs/x'], 1, ['type']],
    'unknown ref target' => [['$ref' => '#/$defs/nope'], 1, ['$ref']],
    'format date' => [['type' => 'string', 'format' => 'date'], '2026-02-30', ['format']],
    'format date-time' => [['type' => 'string', 'format' => 'date-time'], '2026-11-09T10:00:00Z', []],
    'format duration' => [['type' => 'string', 'format' => 'duration'], 'two days', ['format']],
    'format timezone' => [['type' => 'string', 'format' => 'timezone'], 'Mars/Base', ['format']],
    'format cron' => [['type' => 'string', 'format' => 'cron'], '0 9 * * 1-5', []],
    'format field-path' => [['type' => 'string', 'format' => 'field-path'], 'subject.amount', []],
    'format node-id' => [['type' => 'string', 'format' => 'node-id'], 'Not An Id', ['format']],
    'duration def' => [['$ref' => '#/$defs/duration'], ['amount' => 2, 'unit' => 'business_days'], []],
    'annotations kept' => [['type' => 'string', 'title' => 'T', 'description' => 'D', 'default' => 'x', 'examples' => ['x'], 'x-workflows-field-types' => ['decimal']], 'x', []],
]);

// C-06 / C-08: refused at registration, naming the keyword.
it('refuses an unsupported keyword, a remote ref, an unknown format and an invalid pattern', function (array $schema, string $message): void {
    expect(fn () => SchemaValidator::assertSupported($schema, 'acme.thing'))->toThrow(InvalidStepTypeException::class, $message);
})->with([
    [['type' => 'object', 'properties' => ['a' => ['if' => ['type' => 'string']]]], 'if'],
    [['$ref' => 'https://example.com/schema.json'], '$ref'],
    [['type' => 'string', 'format' => 'email'], 'format'],
    [['type' => 'string', 'pattern' => '(unclosed'], 'pattern'],
    [['type' => 'array', 'items' => ['patternProperties' => []]], 'patternProperties'],
    [['oneOf' => [['dependentRequired' => []]]], 'dependentRequired'],
]);

// C-07: a pathological schema or value cannot hang publish.
it('bounds validation depth and work', function (): void {
    $schema = ['type' => 'array', 'items' => ['$ref' => '#/$defs/deep'], '$defs' => ['deep' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/deep']]]];
    $value = [];

    for ($i = 0; $i < 60; $i++) {
        $value = [$value];
    }

    $bounded = array_filter(schemaErrors($schema, $value), static fn (string $error): bool => str_ends_with($error, 'bounds'));

    expect($bounded)->not->toBeEmpty();

    $deep = [];
    for ($i = 0; $i < 40; $i++) {
        $deep = ['items' => $deep];
    }

    expect(fn () => SchemaValidator::assertSupported($deep, 'acme.deep'))->toThrow(InvalidStepTypeException::class, 'depth');
});
