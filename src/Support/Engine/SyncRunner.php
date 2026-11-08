<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support\Engine;

use Closure;

/**
 * The trampoline behind `queue.enabled = off`: queued engine work runs one item after another
 * in a loop, never nested inside the item that enqueued it, so a 200-step instance does not grow
 * the call stack 200 levels deep.
 *
 * @internal
 */
final class SyncRunner
{
    /** @var list<Closure(): mixed> */
    private array $queue = [];

    private bool $draining = false;

    private const int MAX_ITEMS = 100000;

    /**
     * @param  Closure(): mixed  $work
     */
    public function push(Closure $work): void
    {
        $this->queue[] = $work;
    }

    public function drain(): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;
        $ran = 0;

        try {
            while ($this->queue !== [] && $ran < self::MAX_ITEMS) {
                $work = array_shift($this->queue);
                $ran++;
                $work();
            }
        } finally {
            $this->draining = false;
        }
    }

    public function pending(): int
    {
        return count($this->queue);
    }
}
