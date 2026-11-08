<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

use RoundlyConsulting\Workflows\Enums\ConditionOperator;

/**
 * A declarative condition: `When::field('subject.amount')->gt('50000')`, combined with
 * `When::all()`, `When::any()` and `When::not()`. Values may be literals or a Ref.
 */
final readonly class When
{
    /**
     * @param  array<string, mixed>  $value
     */
    private function __construct(
        private array $value,
    ) {}

    public static function field(string $path): PendingClause
    {
        return new PendingClause($path);
    }

    public static function all(self ...$conditions): self
    {
        return new self(['all' => array_map(static fn (self $condition): array => $condition->toArray(), array_values($conditions))]);
    }

    public static function any(self ...$conditions): self
    {
        return new self(['any' => array_map(static fn (self $condition): array => $condition->toArray(), array_values($conditions))]);
    }

    public static function not(self $condition): self
    {
        return new self(['not' => $condition->toArray()]);
    }

    /**
     * @internal
     */
    public static function clause(string $field, ConditionOperator $operator, mixed $value, bool $hasValue = true, bool $insensitive = false): self
    {
        $clause = ['field' => $field, 'op' => $operator->value];

        if ($hasValue) {
            $clause['value'] = GraphBuilder::value($value);
        }

        if ($insensitive) {
            $clause['case'] = 'insensitive';
        }

        return new self($clause);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->value;
    }
}
