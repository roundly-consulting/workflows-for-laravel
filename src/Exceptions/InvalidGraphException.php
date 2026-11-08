<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * Publish (or a draft save) refused the graph; the report names every node and edge at fault.
 */
final class InvalidGraphException extends WorkflowsException
{
    private ?ValidationReport $validationReport = null;

    public static function report(ValidationReport $report): self
    {
        $exception = new self(Messages::get('exceptions.invalid_graph', ['count' => count($report->errorsOnly())]), 'invalid_graph', 422);
        $exception->validationReport = $report;

        return $exception;
    }

    public function validationReport(): ValidationReport
    {
        return $this->validationReport ?? new ValidationReport;
    }
}
