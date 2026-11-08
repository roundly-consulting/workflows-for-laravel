<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\Support\Bridges;

it('boots every bridge when its package is installed', function (): void {
    expect(Bridges::installed())->toBe(['approvals', 'lifecycle', 'notifications', 'opening-hours', 'forms', 'teams'])
        ->and(Workflows::catalogue()->has('approval'))->toBeTrue()
        ->and(Workflows::catalogue()->has('notify'))->toBeTrue()
        ->and(Workflows::catalogue()->export()->find('transition')?->available)->toBeTrue();
});
