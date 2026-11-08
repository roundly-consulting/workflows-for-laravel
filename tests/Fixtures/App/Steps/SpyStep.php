<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps;

use RoundlyConsulting\Workflows\Steps\RetryableStepException;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;

/**
 * Counts its handler calls and returns a configured result (`throw`, `output`, `outcome`).
 */
final class SpyStep extends StepType
{
    public static int $calls = 0;

    /** @var list<StepContext> */
    public static array $contexts = [];

    public function key(): string
    {
        return 'acme.spy';
    }

    public function label(): string
    {
        return 'Spy';
    }

    public function hasSideEffects(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => ['mode' => ['enum' => ['ok', 'throw', 'model', 'nan', 'retryable', 'fail']]],
        ];
    }

    public function handle(StepContext $context): StepResult
    {
        self::$calls++;
        self::$contexts[] = $context;

        return match ($context->config()['mode'] ?? 'ok') {
            'throw' => throw new \RuntimeException('No query results for model [App\\Models\\User] 42'),
            'model' => StepResult::complete(output: ['model' => $context->instance()]),
            'nan' => StepResult::complete(output: ['value' => NAN]),
            'retryable' => throw RetryableStepException::because('busy', 'Try later.'),
            'fail' => StepResult::fail('refused', 'Refused.'),
            default => StepResult::complete(output: ['ok' => true, 'subject' => $context->subject()?->getKey()]),
        };
    }

    public static function reset(): void
    {
        self::$calls = 0;
        self::$contexts = [];
    }
}
