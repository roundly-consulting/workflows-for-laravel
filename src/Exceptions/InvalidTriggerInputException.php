<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\Support\Messages;

/**
 * Manual-trigger input that failed validation, with the field errors.
 */
final class InvalidTriggerInputException extends WorkflowsException
{
    /** @var array<string, list<string>> */
    private array $fieldErrors = [];

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(array $errors): self
    {
        $exception = new self(Messages::get('exceptions.invalid_trigger_input'), 'invalid_trigger_input', 422);
        $exception->fieldErrors = $errors;

        return $exception;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->fieldErrors;
    }
}
