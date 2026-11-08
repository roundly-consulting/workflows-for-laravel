<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

// testing-for-laravel 2.1's scanner doesn't yet recognise toolkit 2.1's new readers
// (`optionalString`, `integerList`, `optionalInteger`, `implementation`, `stringMap`). These keys
// are read through them; each is pinned as read and validated by the per-key dataset of
// EngineTest's "throws the package configuration exception for every invalid key". The list goes
// stale (and fails) the moment the scanner learns the readers.
const TOOLKIT_21_READS = [
    'workflows.actor.system_user', 'workflows.adapters.transition', 'workflows.adapters.webhooks',
    'workflows.authorization.abilities', 'workflows.authorization.authorizer', 'workflows.business_time.calendar_owner',
    'workflows.business_time.weekdays', 'workflows.limits.max_starts_per_minute', 'workflows.owners.context',
    'workflows.owners.membership', 'workflows.parties.roles', 'workflows.parties.user_model',
    'workflows.queue.connection', 'workflows.queue.queue', 'workflows.resources.owner',
    'workflows.resources.subject', 'workflows.resources.user', 'workflows.retry.default.backoff', 'workflows.timezone',
];

it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/workflows.php')->toSatisfyConfigContract([__DIR__.'/../../src', __DIR__.'/../../routes'], ['allowUnread' => TOOLKIT_21_READS]);
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/workflows.php')->not->toBeFile();
});
