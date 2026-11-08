<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Tests\Bridges\Fixtures;

use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * pending → approved | rejected. `approve` is guarded: a request titled "blocked" never passes.
 */
final class PurchaseLifecycle extends LifecycleDefinition
{
    public function define(LifecycleBuilder $lifecycle): void
    {
        $lifecycle->states(['pending', 'approved', 'rejected'])->initial('pending')->terminal('approved', 'rejected');

        $lifecycle->transition('approve')->from('pending')->to('approved')->allowSystem()
            ->when(static fn (TransitionContext $context): bool => $context->subject->getAttribute('title') !== 'blocked', 'budget', 'The budget guard refused.');
        $lifecycle->transition('reject')->from('pending')->to('rejected')->allowSystem();
    }
}
