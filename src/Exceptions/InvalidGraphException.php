<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\DataTransferObjects\ValidationError;
use RoundlyConsulting\Workflows\DataTransferObjects\ValidationReport;
use RoundlyConsulting\Workflows\Support\Messages;

/**
 * Publish (or a draft save) refused the graph; the report names every node and edge at fault, and
 * the message lists the first three (`missing_outcome at legal_cfo (rejected)`). Only codes, node
 * ids and identifier-like params reach the message — never a config value.
 */
final class InvalidGraphException extends WorkflowsException
{
    private const int LISTED = 3;

    private ?ValidationReport $validationReport = null;

    public static function report(ValidationReport $report): self
    {
        $errors = $report->errorsOnly();
        $problems = implode(', ', array_map(self::describe(...), array_slice($errors, 0, self::LISTED)));

        if (count($errors) > self::LISTED) {
            $problems = Messages::get('exceptions.invalid_graph_more', ['problems' => $problems, 'count' => count($errors) - self::LISTED]);
        }

        $exception = $errors === []
            ? self::make('invalid_graph', 422, ['count' => 0])
            : self::make('invalid_graph', 422, ['count' => count($errors), 'problems' => $problems], 'invalid_graph_listed');
        $exception->validationReport = $report;

        return $exception;
    }

    public function validationReport(): ValidationReport
    {
        return $this->validationReport ?? new ValidationReport;
    }

    private static function describe(ValidationError $error): string
    {
        $where = match (true) {
            $error->edge !== null => Messages::get('exceptions.invalid_graph_at_edge', ['code' => $error->code->value, 'from' => $error->edge->from, 'to' => $error->edge->to]),
            $error->node !== null => Messages::get('exceptions.invalid_graph_at_node', ['code' => $error->code->value, 'node' => $error->node]),
            default => $error->code->value,
        };

        $param = null;

        foreach ($error->params as $value) {
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.:\/-]{1,64}$/', $value) === 1) {
                $param = $value;

                break;
            }
        }

        return $param === null ? $where : $where.' ('.$param.')';
    }
}
