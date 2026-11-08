<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\DataTransferObjects;

/**
 * What one `workflows:wake` pass did.
 */
final readonly class WakeReport
{
    public function __construct(
        public int $timers = 0,
        public int $retries = 0,
        public int $timeouts = 0,
        public int $polls = 0,
        public int $escalations = 0,
        public int $schedules = 0,
        public int $reclaimed = 0,
        public int $requeued = 0,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'timers' => $this->timers,
            'retries' => $this->retries,
            'timeouts' => $this->timeouts,
            'polls' => $this->polls,
            'escalations' => $this->escalations,
            'schedules' => $this->schedules,
            'reclaimed' => $this->reclaimed,
            'requeued' => $this->requeued,
        ];
    }
}
