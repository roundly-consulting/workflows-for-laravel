<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Exceptions;

use RoundlyConsulting\Workflows\Support\Messages;
use RuntimeException;

/**
 * The base of every domain exception the package throws. Each carries an HTTP status and a
 * stable machine code beside its translated message, so a host API maps them without matching
 * message text. Messages name keys, node ids and basenames, never a PHP class. `context()` is
 * what Laravel's logger adds to the report: the code and the definition key, node or instance
 * where the exception knows them — never a payload or a secret.
 */
abstract class WorkflowsException extends RuntimeException
{
    /** @var array<string, string> */
    private array $logContext = [];

    final public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['code' => $this->errorCode, ...$this->logContext];
    }

    /**
     * @param  array<string, string>  $context  key, node or instance
     */
    protected function withContext(array $context): static
    {
        $this->logContext = [...$this->logContext, ...$context];

        return $this;
    }

    /**
     * @param  array<string, string|int|float>  $replace
     * @param  string|null  $line  the message line, when it differs from the code
     */
    protected static function make(string $code, int $status, array $replace = [], ?string $line = null): static
    {
        $exception = new static(Messages::get('exceptions.'.($line ?? $code), $replace), $code, $status);
        $context = [];

        foreach (['key', 'node', 'instance'] as $name) {
            if (isset($replace[$name])) {
                $context[$name] = (string) $replace[$name];
            }
        }

        return $exception->withContext($context);
    }
}
