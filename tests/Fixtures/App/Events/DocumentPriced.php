<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Events;

use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\User;

/**
 * A plain host event mapped in code: subject, user, payload properties and an idempotency key.
 */
final class DocumentPriced
{
    public function __construct(
        public Document $document,
        public ?User $by = null,
        public string $amount = '0',
        public string $currency = 'EUR',
        public ?string $submissionId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workflowData(): array
    {
        return ['amount' => $this->amount, 'by' => $this->by];
    }
}
