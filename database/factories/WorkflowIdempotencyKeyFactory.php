<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;
use RoundlyConsulting\Workflows\Support\WorkflowModels;

/**
 * An effect-scope ledger row that never expires.
 *
 * @extends Factory<WorkflowIdempotencyKey>
 */
final class WorkflowIdempotencyKeyFactory extends Factory
{
    /** @var class-string<WorkflowIdempotencyKey> */
    protected $model = WorkflowIdempotencyKey::class;

    public function modelName(): string
    {
        return WorkflowModels::idempotencyKey();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope' => 'effect',
            'key' => $this->faker->unique()->regexify('[a-f0-9]{64}'),
            'result' => ['value' => null],
            'expires_at' => null,
        ];
    }
}
