<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Schema;

use RoundlyConsulting\Workflows\Exceptions\InvalidStepTypeException;
use RoundlyConsulting\Workflows\Graph\GraphParser;
use RoundlyConsulting\Workflows\Support\Durations;
use RoundlyConsulting\Workflows\Support\Schedule\CronExpression;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * A native validator for a JSON Schema (draft 2020-12) subset. Exported schemas stay real JSON
 * Schema, so a UI can feed them to any form renderer. Bounded: depth ≤ 32 and at most 100 000
 * checks per validation, so a pathological schema cannot hang publish.
 *
 * @internal
 */
final class SchemaValidator
{
    public const array KEYWORDS = [
        'type', 'enum', 'const', 'properties', 'required', 'additionalProperties', 'items', 'minItems',
        'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum',
        'exclusiveMaximum', 'multipleOf', 'pattern', 'oneOf', 'anyOf', 'allOf', '$defs', '$ref', 'format',
        'title', 'description', 'default', 'examples', '$schema', '$comment',
    ];

    public const array FORMATS = ['date', 'date-time', 'duration', 'timezone', 'cron', 'field-path', 'node-id'];

    private const int MAX_DEPTH = 32;

    private const int MAX_CHECKS = 100000;

    private int $checks = 0;

    /**
     * @param  array<string, mixed>  $defs
     */
    private function __construct(
        private readonly array $defs,
    ) {}

    /**
     * Refuse a schema with an unsupported keyword, a remote `$ref`, an unknown format or an
     * invalid pattern, naming the type key and the keyword.
     *
     * @param  array<array-key, mixed>  $schema
     *
     * @throws InvalidStepTypeException
     */
    public static function assertSupported(array $schema, string $typeKey, string $path = '', int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw InvalidStepTypeException::keyword($typeKey, 'depth', $path);
        }

        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;

            if (str_starts_with($keyword, 'x-workflows-')) {
                continue;
            }

            if (! in_array($keyword, self::KEYWORDS, true)) {
                throw InvalidStepTypeException::keyword($typeKey, $keyword, $path);
            }

            match ($keyword) {
                '$ref' => is_string($value) && str_starts_with($value, '#/$defs/') ? null : throw InvalidStepTypeException::keyword($typeKey, '$ref', $path),
                'format' => is_string($value) && in_array($value, self::FORMATS, true) ? null : throw InvalidStepTypeException::keyword($typeKey, 'format', $path),
                'pattern' => is_string($value) && @preg_match(self::delimit($value), '') !== false ? null : throw InvalidStepTypeException::pattern($typeKey, $path.'/pattern'),
                default => null,
            };

            if (in_array($keyword, ['properties', '$defs'], true) && is_array($value)) {
                foreach ($value as $name => $child) {
                    if (is_array($child)) {
                        self::assertSupported($child, $typeKey, $path.'/'.$keyword.'/'.$name, $depth + 1);
                    }
                }
            }

            if (in_array($keyword, ['items', 'additionalProperties'], true) && is_array($value)) {
                self::assertSupported($value, $typeKey, $path.'/'.$keyword, $depth + 1);
            }

            if (in_array($keyword, ['oneOf', 'anyOf', 'allOf'], true) && is_array($value)) {
                foreach ($value as $index => $child) {
                    if (is_array($child)) {
                        self::assertSupported($child, $typeKey, $path.'/'.$keyword.'/'.$index, $depth + 1);
                    }
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<SchemaError>
     */
    public static function validate(array $schema, mixed $value): array
    {
        $defs = SharedDefinitions::all();

        if (is_array($schema['$defs'] ?? null)) {
            foreach ($schema['$defs'] as $name => $def) {
                $defs[(string) $name] = $def;
            }
        }

        $validator = new self($defs);
        $errors = [];
        $validator->check($schema, $value, '', 0, $errors);

        return $errors;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  list<SchemaError>  $errors
     */
    private function check(array $schema, mixed $value, string $path, int $depth, array &$errors): void
    {
        if (++$this->checks > self::MAX_CHECKS || $depth > self::MAX_DEPTH) {
            $errors[] = new SchemaError($path, 'bounds', 'a smaller value');

            return;
        }

        if (is_string($schema['$ref'] ?? null)) {
            $name = substr($schema['$ref'], strlen('#/$defs/'));
            $target = $this->defs[$name] ?? null;

            if (! is_array($target)) {
                $errors[] = new SchemaError($path, '$ref', $schema['$ref']);

                return;
            }

            $this->check($target, $value, $path, $depth + 1, $errors);
        }

        if (array_key_exists('type', $schema) && ! $this->matchesType($schema['type'], $value)) {
            $errors[] = new SchemaError($path, 'type', is_array($schema['type']) ? implode('|', array_map(static fn (mixed $type): string => is_string($type) ? $type : 'type', $schema['type'])) : (is_string($schema['type']) ? $schema['type'] : 'type'));

            return;
        }

        if (is_array($schema['enum'] ?? null) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = new SchemaError($path, 'enum', implode('|', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : 'value', array_values($schema['enum']))));
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = new SchemaError($path, 'const', is_scalar($schema['const']) ? (string) $schema['const'] : 'value');
        }

        if (is_string($value)) {
            $this->checkString($schema, $value, $path, $errors);
        }

        if (is_int($value) || is_float($value)) {
            $this->checkNumber($schema, $value, $path, $errors);
        }

        if (is_array($value)) {
            if (array_is_list($value) && ($value !== [] || ! isset($schema['properties']))) {
                $this->checkArray($schema, $value, $path, $depth, $errors);
            }

            if (! array_is_list($value) || $value === []) {
                $this->checkObject($schema, $value, $path, $depth, $errors);
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            if (! is_array($schema[$keyword] ?? null)) {
                continue;
            }

            $passed = 0;
            $collected = [];

            foreach ($schema[$keyword] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                $branchErrors = [];
                $this->check($branch, $value, $path, $depth + 1, $branchErrors);

                if ($branchErrors === []) {
                    $passed++;
                } else {
                    $collected = [...$collected, ...$branchErrors];
                }
            }

            $ok = match ($keyword) {
                'allOf' => $collected === [],
                'anyOf' => $passed > 0,
                default => $passed === 1,
            };

            if (! $ok) {
                $errors[] = $keyword === 'allOf' && $collected !== [] ? $collected[0] : new SchemaError($path, $keyword, 'a matching branch');
            }
        }
    }

    private function matchesType(mixed $type, mixed $value): bool
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidate) {
            $ok = match ($candidate) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  list<SchemaError>  $errors
     */
    private function checkString(array $schema, string $value, string $path, array &$errors): void
    {
        $length = mb_strlen($value);

        if (is_int($schema['minLength'] ?? null) && $length < $schema['minLength']) {
            $errors[] = new SchemaError($path, 'minLength', (string) $schema['minLength']);
        }

        if (is_int($schema['maxLength'] ?? null) && $length > $schema['maxLength']) {
            $errors[] = new SchemaError($path, 'maxLength', (string) $schema['maxLength']);
        }

        // Patterns are author-written in host code (validated at registration); the value
        // length is capped before matching.
        if (is_string($schema['pattern'] ?? null) && ($length > 10000 || @preg_match(self::delimit($schema['pattern']), $value) !== 1)) {
            $errors[] = new SchemaError($path, 'pattern', $schema['pattern']);
        }

        if (is_string($schema['format'] ?? null) && ! $this->matchesFormat($schema['format'], $value)) {
            $errors[] = new SchemaError($path, 'format', $schema['format']);
        }
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  list<SchemaError>  $errors
     */
    private function checkNumber(array $schema, int|float $value, string $path, array &$errors): void
    {
        foreach (['minimum' => fn (int|float $limit): bool => $value >= $limit, 'maximum' => fn (int|float $limit): bool => $value <= $limit,
            'exclusiveMinimum' => fn (int|float $limit): bool => $value > $limit, 'exclusiveMaximum' => fn (int|float $limit): bool => $value < $limit] as $keyword => $test) {
            $limit = $schema[$keyword] ?? null;

            if ((is_int($limit) || is_float($limit)) && ! $test($limit)) {
                $errors[] = new SchemaError($path, $keyword, (string) $limit);
            }
        }

        if (is_int($schema['multipleOf'] ?? null) && $schema['multipleOf'] > 0 && (! is_int($value) || $value % $schema['multipleOf'] !== 0)) {
            $errors[] = new SchemaError($path, 'multipleOf', (string) $schema['multipleOf']);
        }
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  list<mixed>  $value
     * @param  list<SchemaError>  $errors
     */
    private function checkArray(array $schema, array $value, string $path, int $depth, array &$errors): void
    {
        if (is_int($schema['minItems'] ?? null) && count($value) < $schema['minItems']) {
            $errors[] = new SchemaError($path, 'minItems', (string) $schema['minItems']);
        }

        if (is_int($schema['maxItems'] ?? null) && count($value) > $schema['maxItems']) {
            $errors[] = new SchemaError($path, 'maxItems', (string) $schema['maxItems']);
        }

        if (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map(static fn (mixed $item): string => (string) json_encode($item), $value))) !== count($value)) {
            $errors[] = new SchemaError($path, 'uniqueItems', 'unique items');
        }

        if (is_array($schema['items'] ?? null)) {
            foreach ($value as $index => $item) {
                $this->check($schema['items'], $item, $path.'/'.$index, $depth + 1, $errors);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  array<array-key, mixed>  $value
     * @param  list<SchemaError>  $errors
     */
    private function checkObject(array $schema, array $value, string $path, int $depth, array &$errors): void
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if (is_array($schema['required'] ?? null)) {
            foreach ($schema['required'] as $required) {
                if (is_string($required) && ! array_key_exists($required, $value)) {
                    $errors[] = new SchemaError($path.'/'.$required, 'required', $required);
                }
            }
        }

        foreach ($value as $key => $item) {
            $key = (string) $key;

            if (isset($properties[$key]) && is_array($properties[$key])) {
                $this->check($properties[$key], $item, $path.'/'.$key, $depth + 1, $errors);

                continue;
            }

            $additional = $schema['additionalProperties'] ?? true;

            if ($additional === false) {
                $errors[] = new SchemaError($path.'/'.$key, 'additionalProperties', 'no additional properties');
            } elseif (is_array($additional)) {
                $this->check($additional, $item, $path.'/'.$key, $depth + 1, $errors);
            }
        }
    }

    private function matchesFormat(string $format, string $value): bool
    {
        return match ($format) {
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', $value) === 1 && strtotime($value) !== false,
            'duration' => Durations::iso($value) !== null,
            'timezone' => WorkflowsConfig::isTimezone($value),
            'cron' => CronExpression::isValid($value),
            'field-path' => preg_match('/^(now|(subject|owner|input|trigger)\.[a-z0-9_.]+|steps\.[a-z][a-z0-9_]*\.output(\.[A-Za-z0-9_.]+)?)$/', $value) === 1,
            'node-id' => preg_match(GraphParser::NODE_ID, $value) === 1,
            default => false,
        };
    }

    private static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }
}
