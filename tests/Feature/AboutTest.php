<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('renders its about section without leaking anything', function (): void {
    expect('workflows')->toLeakNoSecrets([], mustRender: ['Step types', 'Queue', 'Key type', 'Bridges', 'Limits', 'Webhooks']);
});

it('reports the queue, limits and webhooks as configured', function (): void {
    config()->set('workflows.queue.enabled', 'on');
    config()->set('workflows.queue.connection', 'redis');
    config()->set('workflows.limits.max_starts_per_minute', 'off');

    Artisan::call('about', ['--only' => 'workflows']);

    expect(Artisan::output())
        ->toMatch('/Queue\W+ON \(connection SET, queue DEFAULT\)/')
        ->toMatch('/unlimited starts\/min/')
        ->toMatch('/Webhooks\W+OFF/');
});
