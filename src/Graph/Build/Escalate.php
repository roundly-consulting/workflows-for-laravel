<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

/**
 * One escalation action: reassign the waiting step, notify someone, or start another workflow.
 */
final readonly class Escalate
{
    /**
     * @param  array<string, mixed>  $value
     */
    private function __construct(
        private array $value,
    ) {}

    public static function reassign(Party ...$to): self
    {
        return new self(['type' => 'reassign', 'to' => Party::list(array_values($to))]);
    }

    public static function notify(string $notification, Party ...$recipients): self
    {
        return new self(['type' => 'notify', 'notification' => $notification, 'recipients' => Party::list(array_values($recipients))]);
    }

    /**
     * Start another definition of the same owner as a child (same subject when it has one).
     */
    public static function startWorkflow(string $definition): self
    {
        return new self(['type' => 'start_workflow', 'definition' => $definition]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->value;
    }
}
