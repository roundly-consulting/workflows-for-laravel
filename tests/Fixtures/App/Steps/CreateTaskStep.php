<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps;

use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepFailedException;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Tests\Fixtures\App\Models\Task;
use RuntimeException;

/**
 * The README's host step: creates a task once per visit. `crash_after_effect: true` throws after
 * the effect on the first attempt (a worker dying after the write).
 */
final class CreateTaskStep extends StepType
{
    public static int $calls = 0;

    public function key(): string
    {
        return 'acme.create_task';
    }

    public function label(): string
    {
        return 'Create task';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function ability(): ?string
    {
        return 'createTask';
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title'],
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 200],
                'crash_after_effect' => ['type' => 'boolean'],
                'fail_for_good' => ['type' => 'boolean'],
            ],
        ];
    }

    public function handle(StepContext $context): StepResult
    {
        self::$calls++;

        $taskId = $context->once('task', fn (): int => Task::query()->create([
            'title' => $context->config()['title'],
            'workflow_key' => $context->idempotencyKey(),
        ])->id);

        if (($context->config()['crash_after_effect'] ?? false) === true && $context->attempt() === 1) {
            throw new RuntimeException('Worker died after the write in App\\Models\\Task.');
        }

        if (($context->config()['fail_for_good'] ?? false) === true && FlakyState::$failing) {
            throw StepFailedException::because('downstream_refused', 'The downstream refused.');
        }

        return StepResult::complete(output: ['task_id' => $taskId]);
    }

    public function simulate(StepContext $context): StepResult
    {
        return StepResult::complete(output: ['task_id' => null])->wouldDo('Create task "'.$context->config()['title'].'"');
    }
}
