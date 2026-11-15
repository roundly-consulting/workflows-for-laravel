<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;

/**
 * The notifications-for-laravel context the `notify` step and the `notify` escalation action file
 * inbox items under, when `workflows.bridges.notifications.context` names this class instead of
 * `owner`. Return a `RoundlyConsulting\Notifications\Context`, a model, a context key string,
 * or null for none.
 */
interface ResolvesNotificationContext
{
    public function notificationContext(WorkflowInstance $instance, ?Model $owner, ?Model $subject): object|string|null;
}
