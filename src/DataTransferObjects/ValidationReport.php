<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Enums\ValidationCode;

/**
 * The result of validating a graph: valid when no finding is an error (warnings never block).
 */
final readonly class ValidationReport
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(
        public array $errors = [],
    ) {}

    public function valid(): bool
    {
        foreach ($this->errors as $error) {
            if ($error->isError()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<ValidationError>
     */
    public function errorsOnly(): array
    {
        return array_values(array_filter($this->errors, static fn (ValidationError $error): bool => $error->isError()));
    }

    /**
     * @return list<ValidationError>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->errors, static fn (ValidationError $error): bool => ! $error->isError()));
    }

    public function has(ValidationCode $code, ?string $node = null): bool
    {
        return $this->find($code, $node) !== null;
    }

    public function find(ValidationCode $code, ?string $node = null): ?ValidationError
    {
        foreach ($this->errors as $error) {
            if ($error->code === $code && ($node === null || $error->node === $node)) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid(),
            'errors' => array_map(static fn (ValidationError $error): array => $error->toArray(), $this->errors),
        ];
    }
}
