<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Workflows\Contracts\ResolvesNotificationContext;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * A host's notification context resolver: files workflow notifications under a `workflows`
 * context labelled after the definition, or returns what a test sets.
 */
final class NotificationContexts implements ResolvesNotificationContext
{
    public static ?object $returns = null;

    public function notificationContext(WorkflowInstance $instance, ?Model $owner, ?Model $subject): object|string|null
    {
        return self::$returns ?? Context::key('workflows', 'Workflow '.($instance->definition->key ?? ''));
    }
}
