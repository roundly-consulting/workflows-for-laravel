<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Fixtures\App\Steps;

use RoundlyConsulting\Workflows\DataTransferObjects\Signal;
use RoundlyConsulting\Workflows\Steps\Outcome;
use RoundlyConsulting\Workflows\Steps\StepContext;
use RoundlyConsulting\Workflows\Steps\StepResult;
use RoundlyConsulting\Workflows\Steps\StepType;
use RoundlyConsulting\Workflows\Steps\WaitSpec;

/**
 * A host type with approval-like outcomes (approved / rejected / expired), an owner-specific
 * `reviewer` id and a `format: node-id` reference: validation and import tests without the
 * approvals bridge.
 */
final class ReviewStep extends StepType
{
    public function key(): string
    {
        return 'acme.review';
    }

    public function label(): string
    {
        return 'Review';
    }

    public function hasSideEffects(): bool
    {
        return false;
    }

    public function supportsEscalations(): bool
    {
        return true;
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reviewer' => ['type' => ['integer', 'null'], 'x-workflows-owner-specific' => true],
                'after' => ['type' => 'string', 'format' => 'node-id'],
            ],
        ];
    }

    public function outcomes(array $config): array
    {
        return [Outcome::of('approved'), Outcome::of('rejected'), Outcome::of('expired')];
    }

    public function handle(StepContext $context): StepResult
    {
        return StepResult::wait(WaitSpec::event('review.decided'));
    }

    public function resume(StepContext $context, Signal $signal): StepResult
    {
        $decision = $signal->payload['decision'] ?? 'approved';

        return StepResult::complete(is_string($decision) ? $decision : 'approved');
    }
}
