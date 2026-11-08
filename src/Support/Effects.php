<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Crypto\Facades\Crypto;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;

/**
 * The effect half of the idempotency ledger: `StepContext::once()` runs the closure and records
 * its result in the same transaction, so a retry of the same visit returns the stored result and
 * never repeats the effect. Effect keys never expire; they go with their instance tree.
 *
 * @internal
 */
final class Effects
{
    /**
     * What `once()` recorded for this step key and name, without running anything: null when
     * nothing was recorded.
     */
    public static function recall(string $stepKey, string $name): mixed
    {
        $model = WorkflowModels::idempotencyKey();
        $digest = Crypto::digest()->hex($stepKey.'|'.$name);
        $row = $model::query()->where('scope', 'effect')->where('key', $digest)->first();

        return $row instanceof WorkflowIdempotencyKey ? ($row->result['value'] ?? null) : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|mixed
     */
    public static function once(string $stepKey, string $name, int|string $instanceId, Closure $callback): mixed
    {
        $digest = Crypto::digest()->hex($stepKey.'|'.$name);
        $model = WorkflowModels::idempotencyKey();
        $connection = (new $model)->getConnection();

        $existing = $model::query()->where('scope', 'effect')->where('key', $digest)->first();

        if ($existing instanceof WorkflowIdempotencyKey) {
            return $existing->result['value'] ?? null;
        }

        try {
            return $connection->transaction(static function () use ($callback, $digest, $model, $instanceId): mixed {
                $value = $callback();

                $row = new $model;
                $row->scope = 'effect';
                $row->key = $digest;
                $row->instance_id = $instanceId;
                $row->result = ['value' => $value];
                $row->expires_at = null;
                $row->save();

                return $value;
            });
        } catch (UniqueConstraintViolationException) {
            $winner = $model::query()->where('scope', 'effect')->where('key', $digest)->first();

            return $winner instanceof WorkflowIdempotencyKey ? ($winner->result['value'] ?? null) : null;
        }
    }
}
