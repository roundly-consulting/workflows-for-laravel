<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * An instance: running a step, waiting on a timer, event or approval, or ended.
 */
#[TranslatedLabels('workflows::enums.instance_status')]
enum InstanceStatus: string
{
    use Helpers;

    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return $this === self::Running || $this === self::Waiting;
    }
}
