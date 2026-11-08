<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Document;

/**
 * No access check, and no policy for its subject: notifications refuses it (MissingAccessCheckException).
 */
final class UncheckedNotice extends NotificationType
{
    public function key(): string
    {
        return 'document.unchecked';
    }

    public function channels(): array
    {
        return [Channel::Inbox];
    }

    public function subject(): ?string
    {
        return Document::class;
    }

    public function title(NotificationPayload $payload): string
    {
        return 'Unchecked';
    }
}
