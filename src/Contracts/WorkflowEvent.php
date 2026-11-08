<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A Laravel event that maps itself to a trigger key, instead of a `workflows.events` entry.
 * Listed in `workflows.events` (as `Event::class => '*'`) or registered with
 * `Workflows::triggers()->listen(Event::class)`, it emits after the host's transaction commits.
 */
interface WorkflowEvent
{
    public function workflowKey(): string;

    public function workflowSubject(): ?Model;

    /**
     * @return array<string, mixed>
     */
    public function workflowPayload(): array;

    public function workflowUser(): ?Model;

    public function workflowIdempotencyKey(): ?string;
}
