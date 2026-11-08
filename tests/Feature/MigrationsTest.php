<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Workflows\Exceptions\InvalidWorkflowsConfigurationException;
use RoundlyConsulting\Workflows\Models\StepRun;
use RoundlyConsulting\Workflows\Models\WorkflowDefinition;
use RoundlyConsulting\Workflows\Models\WorkflowDryRun;
use RoundlyConsulting\Workflows\Models\WorkflowEscalation;
use RoundlyConsulting\Workflows\Models\WorkflowIdempotencyKey;
use RoundlyConsulting\Workflows\Models\WorkflowInstance;
use RoundlyConsulting\Workflows\Models\WorkflowVersion;
use RoundlyConsulting\Workflows\Models\WorkflowWait;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;
use RoundlyConsulting\Workflows\WorkflowsServiceProvider;

$tables = [
    "WorkflowsConfig::table('definitions')" => 'workflow_definitions',
    "WorkflowsConfig::table('versions')" => 'workflow_versions',
    "WorkflowsConfig::table('instances')" => 'workflow_instances',
    "WorkflowsConfig::table('step_runs')" => 'workflow_step_runs',
    "WorkflowsConfig::table('waits')" => 'workflow_waits',
    "WorkflowsConfig::table('escalations')" => 'workflow_escalations',
    "WorkflowsConfig::table('idempotency_keys')" => 'workflow_idempotency_keys',
    "WorkflowsConfig::table('dry_runs')" => 'workflow_dry_runs',
];

it('publishes its eight migrations instead of auto-loading them', function (): void {
    expect(WorkflowsServiceProvider::class)
        ->toNotAutoLoadMigrations()
        ->toPublishMigrationsTimestamped('workflows-migrations', 8);
});

it('runs its migrations in an order all 13 foreign keys can follow', function () use ($tables): void {
    expect(__DIR__.'/../../database/migrations')->toHaveRunnableMigrationOrder(foreignKeys: 13, tableResolvers: $tables);
});

it('applies on postgres and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('pgsql', 8)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no PostgreSQL connection')->group('real-engine');

it('applies on mysql and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('mysql', 8)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no MySQL connection')->group('real-engine');

it('declares every model swappable through its config key', function (string $model, string $key): void {
    expect($model)->toBeSwappableVia($key);
})->with([
    [WorkflowDefinition::class, 'workflows.models.definition'],
    [WorkflowVersion::class, 'workflows.models.version'],
    [WorkflowInstance::class, 'workflows.models.instance'],
    [StepRun::class, 'workflows.models.step_run'],
    [WorkflowWait::class, 'workflows.models.wait'],
    [WorkflowEscalation::class, 'workflows.models.escalation'],
    [WorkflowIdempotencyKey::class, 'workflows.models.idempotency_key'],
    [WorkflowDryRun::class, 'workflows.models.dry_run'],
]);

it('keeps the idempotency ledger without soft deletes (the documented exception)', function (): void {
    expect(in_array(SoftDeletes::class, class_uses_recursive(WorkflowIdempotencyKey::class), true))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(WorkflowInstance::class), true))->toBeTrue();
});

it('refuses an invalid or unknown table name', function (string $handle, mixed $value): void {
    config()->set('workflows.tables.'.$handle, $value);

    WorkflowsConfig::table($handle);
})->with([
    'too long' => ['instances', str_repeat('x', 41)],
    'not a name' => ['versions', 'Workflow Versions'],
    'unknown handle' => ['nope', 'x'],
])->throws(InvalidWorkflowsConfigurationException::class);

it('builds every model through its factory', function (string $model): void {
    $created = $model::factory()->create();

    expect($created)->toBeInstanceOf($model)->and($created->exists)->toBeTrue();
})->with([
    WorkflowDefinition::class, WorkflowVersion::class, WorkflowInstance::class, StepRun::class,
    WorkflowWait::class, WorkflowEscalation::class, WorkflowIdempotencyKey::class, WorkflowDryRun::class,
]);
