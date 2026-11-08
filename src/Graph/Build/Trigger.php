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

    /**
     * An inbound webhook: through the hook-id route (`$idempotencyFrom`: `header:<name>` or
     * `payload:<path>`), and/or a webhooks-for-laravel inbound endpoint (`$endpoint`: its id; the
     * delivery id keys the start).
     */
    public static function webhook(?string $idempotencyFrom = null, int|string|null $endpoint = null): self
    {
        $config = ['trigger' => 'webhook'];

        if ($idempotencyFrom !== null) {
            $config['idempotency'] = ['from' => $idempotencyFrom];
        }

        if ($endpoint !== null) {
            $config['endpoint'] = $endpoint;
        }

        return new self($config);
    }

    /**
     * Run a schedule once per subject of the definition's subject type (each matching `$where`,
     * at most `$limit` per slot).
     */
    public function forEach(?When $where = null, ?int $limit = null): self
    {
        $forEach = [];

        if ($where !== null) {
            $forEach['where'] = $where->toArray();
        }

        if ($limit !== null) {
            $forEach['limit'] = $limit;
        }

        return new self([...$this->value, 'for_each' => $forEach]);
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
