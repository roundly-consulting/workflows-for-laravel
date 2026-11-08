<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Contracts\WorkflowEvent;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

/**
 * A self-describing host event: it names its own key, subject, user and idempotency key.
 */
final class DocumentSigned implements WorkflowEvent
{
    public function __construct(
        public Document $document,
        public ?User $signer = null,
    ) {}

    public function workflowKey(): string
    {
        return 'document.signed';
    }

    public function workflowSubject(): ?Model
    {
        return $this->document;
    }

    public function workflowPayload(): array
    {
        return ['signed' => true];
    }

    public function workflowUser(): ?Model
    {
        return $this->signer;
    }

    public function workflowIdempotencyKey(): ?string
    {
        return 'signed:'.$this->document->id;
    }
}
