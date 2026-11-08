<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

use RoundlyConsulting\Workflows\Exceptions\WorkflowsException;

/**
 * One matching definition an emit could not start (no triggering user for `on_behalf_of`, a
 * definition retired meanwhile, a subject of another owner): the emit carries on with the others
 * and still delivers the event to the waits.
 */
final readonly class EmitFailure
{
    public function __construct(
        public string $definition,
        public WorkflowsException $exception,
    ) {}

    public function code(): string
    {
        return $this->exception->errorCode();
    }
}
