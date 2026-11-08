<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;

/**
 * A type about the core Document subject, and one without an access check (a host wiring error).
 */
final class DocumentNotice extends NotificationType
{
    public function key(): string
    {
        return 'document.notice';
    }

    public function channels(): array
    {
        return [Channel::Inbox];
    }

    public function subject(): ?string
    {
        return Document::class;
    }

    public function canAccess(Model $recipient, ?Model $subject): bool
    {
        return true;
    }

    public function title(NotificationPayload $payload): string
    {
        return 'Notice';
    }
}
