<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * A step failed with details that are not a JSON object of plain values: an object, a model, a
 * closure, a resource, INF/NAN, nesting deeper than 32 levels, or a list at the top. Thrown where
 * the details are given (`StepResult::fail()`, `withDetails()`), so a step author's mistake is
 * loud in tests; inside a handler the engine records it as a permanent `invalid_failure_details`
 * failure. The message names the path, never a value.
 */
final class InvalidFailureDetailsException extends WorkflowsException
{
    public static function notPlain(string $path): self
    {
        return self::make('invalid_failure_details', 500, ['path' => $path]);
    }

    public static function notAnObject(): self
    {
        return self::make('invalid_failure_details', 500, [], 'invalid_failure_details_list');
    }
}
