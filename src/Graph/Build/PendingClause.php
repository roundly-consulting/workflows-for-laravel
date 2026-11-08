<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

use RoundlyConsulting\Workflows\Enums\ConditionOperator;

/**
 * A field waiting for its operator: `When::field('subject.amount')->gt('50000')`.
 */
final readonly class PendingClause
{
    public function __construct(
        private string $field,
    ) {}

    public function equals(mixed $value): When
    {
        return When::clause($this->field, ConditionOperator::Equals, $value);
    }

    /**
     * @param  list<mixed>|Ref  $values
     */
    public function in(array|Ref $values): When
    {
        return When::clause($this->field, ConditionOperator::In, $values);
    }

    public function gt(mixed $value): When
    {
        return When::clause($this->field, ConditionOperator::Gt, $value);
    }

    public function lt(mixed $value): When
    {
        return When::clause($this->field, ConditionOperator::Lt, $value);
    }

    public function between(mixed $from, mixed $to): When
    {
        return When::clause($this->field, ConditionOperator::Between, [$from, $to]);
    }

    public function isEmpty(): When
    {
        return When::clause($this->field, ConditionOperator::IsEmpty, null, hasValue: false);
    }

    public function contains(mixed $value, bool $insensitive = false): When
    {
        return When::clause($this->field, ConditionOperator::Contains, $value, insensitive: $insensitive);
    }

    public function before(mixed $value): When
    {
        return When::clause($this->field, ConditionOperator::Before, $value);
    }

    public function after(mixed $value): When
    {
        return When::clause($this->field, ConditionOperator::After, $value);
    }
}
