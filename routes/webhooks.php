<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Workflows\Http\Controllers\WebhookController;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

// Fail closed: enabled without signature middleware, the route is not registered at all
// (`workflows:doctor` fails on it).
if (WorkflowsConfig::webhookVerifyMiddleware() === []) {
    return;
}

Route::middleware([...WorkflowsConfig::webhooksMiddleware(), ...WorkflowsConfig::webhookVerifyMiddleware()])
    ->prefix(WorkflowsConfig::webhooksPrefix())
    ->group(static function (): void {
        Route::post('hooks/{hook}', WebhookController::class)->name('workflows.webhooks.start');
    });
