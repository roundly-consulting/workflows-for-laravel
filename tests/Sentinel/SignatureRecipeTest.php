<?php

declare(strict_types=1);

use RoundlyConsulting\Workflows\Models\WorkflowInstance;

// C-126: the documented recipe — `verify_middleware = ['sentinel.signed']` — guards the route.
it('guards the webhook route with sentinel signatures', function (): void {
    config()->set('workflows.webhooks.enabled', 'on');
    config()->set('workflows.webhooks.verify_middleware', ['sentinel.signed']);
    require __DIR__.'/../../routes/webhooks.php';
    app('router')->getRoutes()->refreshNameLookups();

    $hook = published('hook', graph([
        node('start', 'trigger', ['trigger' => 'webhook', 'idempotency' => ['from' => 'header:Idempotency-Key']]),
        node('done', 'end'),
    ], [['start', 'done']]), null, null)->webhook_id;

    $status = $this->postJson('/workflows/hooks/'.$hook, ['a' => 1], ['Idempotency-Key' => 'k'])->status();

    expect($status)->toBeGreaterThanOrEqual(400)->toBeLessThan(500)
        ->and(WorkflowInstance::query()->count())->toBe(0)
        ->and(app('router')->getRoutes()->getByName('workflows.webhooks.start')?->gatherMiddleware())->toContain('sentinel.signed');
});
