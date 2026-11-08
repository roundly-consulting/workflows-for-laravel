<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\Severity;
use RoundlyConsulting\Workflows\Enums\ValidationCode;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * One machine-readable finding: the code, the node and/or edge it is about, a JSON pointer
 * within the node, scalar params, a translated message (never a class name), and its severity.
 */
final readonly class ValidationError
{
    /**
     * @param  array<string, scalar|list<scalar>>  $params
     */
    public function __construct(
        public ValidationCode $code,
        public ?string $node = null,
        public ?EdgeReference $edge = null,
        public ?string $path = null,
        public array $params = [],
        public Severity $severity = Severity::Error,
        public string $message = '',
    ) {}

    /**
     * @param  array<string, scalar|list<scalar>>  $params
     */
    public static function make(
        ValidationCode $code,
        ?string $node = null,
        ?EdgeReference $edge = null,
        ?string $path = null,
        array $params = [],
        Severity $severity = Severity::Error,
    ): self {
        $replace = ['node' => $node ?? '', 'path' => $path ?? ''];

        foreach ($params as $key => $value) {
            $replace[$key] = is_array($value) ? implode(', ', array_map(strval(...), $value)) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return new self($code, $node, $edge, $path, $params, $severity, Messages::get('validation.'.$code->value, $replace));
    }

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }

    /**
     * @return array{code: string, severity: string, message: string, node: ?string, edge: array{from: string, to: string, outcome: ?string}|null, path: ?string, params: array<string, scalar|list<scalar>>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'node' => $this->node,
            'edge' => $this->edge?->toArray(),
            'path' => $this->path,
            'params' => $this->params,
        ];
    }
}
