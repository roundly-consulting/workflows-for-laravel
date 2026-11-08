<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

/**
 * The template payload is not a roundly.workflows.template/v1 document.
 */
final class InvalidTemplateException extends WorkflowsException
{
    public static function because(string $reason): self
    {
        return self::make('invalid_template', 422, ['reason' => $reason]);
    }
}
