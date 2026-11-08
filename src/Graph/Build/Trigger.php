<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Graph\Build;

/**
 * What starts a run: `Trigger::manual(Input ...)`, `form()`, `event()`, `schedule()` or
 * `webhook()`; `->when()` adds a start condition.
 */
final readonly class Trigger
{
    /**
     * @param  array<string, mixed>  $value
     */
    private function __construct(
        private array $value,
    ) {}

    public static function manual(Input ...$inputs): self
    {
        $config = ['trigger' => 'manual'];

        if ($inputs !== []) {
            $config['inputs'] = array_map(static fn (Input $input): array => $input->toArray(), array_values($inputs));
        }

        return new self($config);
    }

    /**
     * A manual trigger whose input is validated by a forms-for-laravel form.
     */
    public static function form(string $form): self
    {
        return new self(['trigger' => 'manual', 'form' => $form]);
    }

    public static function event(string $event, ?When $condition = null): self
    {
        $trigger = new self(['trigger' => 'event', 'event' => $event]);

        return $condition === null ? $trigger : $trigger->when($condition);
    }

    /**
     * A cron expression, or a preset (`['every' => 'weekday', 'at' => '09:00']`).
     *
     * @param  array<string, int|string>|null  $preset
     */
    public static function schedule(?string $cron = null, ?array $preset = null, ?string $timezone = null): self
    {
        $config = ['trigger' => 'schedule'];

        if ($cron !== null) {
            $config['cron'] = $cron;
        }

        if ($preset !== null) {
            $config['preset'] = $preset;
        }

        if ($timezone !== null) {
            $config['timezone'] = $timezone;
        }

        return new self($config);
    }

    public static function webhook(?string $idempotencyFrom = null): self
    {
        return new self($idempotencyFrom === null
            ? ['trigger' => 'webhook']
            : ['trigger' => 'webhook', 'idempotency' => ['from' => $idempotencyFrom]]);
    }

    /**
     * Start only when the condition holds (otherwise the start is skipped).
     */
    public function when(When $condition): self
    {
        return new self([...$this->value, 'condition' => $condition->toArray()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->value;
    }
}
