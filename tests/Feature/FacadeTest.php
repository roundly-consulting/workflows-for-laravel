<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Facades\Workflows;
use RoundlyConsulting\Workflows\WorkflowsManager;

it('pins the facade contract', function (): void {
    expect(Workflows::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

// C-131: Octane keeps the singleton manager across requests, so it must carry nothing per request.
it('holds no request state on the manager', function (): void {
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(WorkflowsManager::class))->getProperties(),
    );

    published('flow', graph([node('start', 'trigger', ['trigger' => 'manual']), node('done', 'end')], [['start', 'done']]), null, null);
    $first = Workflows::start('flow')->input(['a' => 1])->by(user('Rita'));
    $second = Workflows::start('flow');

    expect($properties)->toBe(['container'])
        ->and($first)->not->toBe($second)
        ->and($second->dispatch()->instance?->starter_id)->toBeNull()
        ->and($second->dispatch()->instance?->trigger_payload['input'] ?? [])->toBe([]);
});
