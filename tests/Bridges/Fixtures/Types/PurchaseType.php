<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Models\PurchaseRequest;

/**
 * The fixture notification types (`purchase.escalated`, `.approved`, `.rejected`, `.expired`):
 * inbox only, every recipient may see the request.
 */
abstract class PurchaseType extends NotificationType
{
    public function category(): string
    {
        return 'purchases';
    }

    public function channels(): array
    {
        return [Channel::Inbox];
    }

    public function subject(): ?string
    {
        return PurchaseRequest::class;
    }

    public function canAccess(Model $recipient, ?Model $subject): bool
    {
        return true;
    }

    public function title(NotificationPayload $payload): string
    {
        return $this->key();
    }
}
