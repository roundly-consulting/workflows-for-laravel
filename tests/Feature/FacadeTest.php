<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Facades\Workflows;

it('pins the facade contract', function (): void {
    expect(Workflows::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});
