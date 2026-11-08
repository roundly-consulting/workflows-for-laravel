<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * A mutating operation the manager performs (and the fake records).
 */
#[TranslatedLabels('workflows::enums.workflow_operation')]
enum WorkflowOperation: string
{
    use Helpers;

    case CreateDefinition = 'create_definition';
    case RestoreDefinition = 'restore_definition';
    case Edit = 'edit';
    case DiscardDraft = 'discard_draft';
    case Publish = 'publish';
    case Retire = 'retire';
    case Activate = 'activate';
    case ResolveFlag = 'resolve_flag';
    case Import = 'import';
    case Start = 'start';
    case Emit = 'emit';
    case Signal = 'signal';
    case Webhook = 'webhook';
    case Retry = 'retry';
    case Cancel = 'cancel';
    case Touch = 'touch';
    case Wake = 'wake';
    case Prune = 'prune';
    case DryRun = 'dry_run';
    case RotateWebhook = 'rotate_webhook';
    case Sync = 'sync';
}
