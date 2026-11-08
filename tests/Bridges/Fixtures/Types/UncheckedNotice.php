<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures\Types;

use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * No access check and no policy: notifications refuses it (MissingAccessCheckException).
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

    public function title(NotificationPayload $payload): string
    {
        return 'Unchecked';
    }
}
