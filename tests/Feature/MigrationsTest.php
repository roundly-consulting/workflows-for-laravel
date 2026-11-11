<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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

// Every key type, not just the bigint default: a uuid/ulid id is a fluent primary key, which
// Laravel compiles after the closure's own commands. On Postgres that put the instances table's
// self-referencing foreign key ahead of its primary key, and a fresh migrate failed (1.2.1).
it('applies on postgres and refuses a broken order there', function (string $keyType): void {
    config()->set('workflows.primary_key_type', $keyType);

    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('pgsql', 8)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'pgsql');
})->with(['bigint', 'uuid', 'ulid'])
    ->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no PostgreSQL connection')->group('real-engine');

it('applies on mysql and refuses a broken order there', function (string $keyType): void {
    config()->set('workflows.primary_key_type', $keyType);

    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('mysql', 8)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'mysql');
})->with(['bigint', 'uuid', 'ulid'])
    ->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no MySQL connection')->group('real-engine');

// Engine-free pin of the same bug, so the default sqlite run catches it too: compiled for
// Postgres (pretend mode, no server needed), every key type creates the instances table's
// primary key before the foreign key that points back at it.
it('creates the instances primary key before its self-reference on postgres', function (string $keyType): void {
    config()->set('workflows.primary_key_type', $keyType);
    config()->set('database.connections.pgsql_compile', [
        'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none',
        'username' => 'none', 'password' => '', 'prefix' => '', 'search_path' => 'public',
    ]);
    $default = config('database.default');
    config()->set('database.default', 'pgsql_compile');

    try {
        $migration = require __DIR__.'/../../database/migrations/0003_create_workflow_instances_table.php';
        $statements = array_column(DB::connection('pgsql_compile')->pretend(static fn () => $migration->up()), 'query');
    } finally {
        config()->set('database.default', $default);
    }

    $create = array_search(true, array_map(static fn (string $sql): bool => str_starts_with($sql, 'create table'), $statements), true);
    $primary = array_search(true, array_map(static fn (string $sql): bool => str_contains($sql, 'primary key'), $statements), true);
    $selfReference = array_search(true, array_map(static fn (string $sql): bool => str_contains($sql, 'winst_parent_fk'), $statements), true);

    expect($create)->toBe(0)
        ->and($primary)->toBeInt()
        ->and($selfReference)->toBeInt()
        ->and($primary)->toBeLessThan($selfReference);
})->with(['bigint', 'uuid', 'ulid']);

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

// W-29: the toolkit's StoresUtcTimestamps keeps microseconds; the UtcDateTime cast stores whole
// seconds, so the instant a model holds after save is the one the database holds — no
// freshTimestamp() override is needed for claim and lease comparisons.
it('stores timestamps as whole UTC seconds equal to what it reads back', function (): void {
    config()->set('app.timezone', 'America/New_York');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-08 10:00:00.987654', 'UTC'));

    $instance = WorkflowInstance::factory()->create();
    $raw = WorkflowInstance::query()->toBase()->where('id', $instance->id)->value('created_at');

    expect($instance->created_at?->format('Y-m-d H:i:s.u e'))->toBe('2026-11-08 10:00:00.000000 UTC')
        ->and($instance->fresh()?->created_at?->equalTo($instance->created_at))->toBeTrue()
        ->and(substr((string) $raw, 0, 19))->toBe('2026-11-08 10:00:00');

    CarbonImmutable::setTestNow();
});

// The CI key-type legs set WORKFLOWS_PRIMARY_KEY_TYPE: a leg whose env stopped reaching the config
// would run bigint and report green under a uuid/ulid name.
it('runs on the primary key type the leg asks for', function (): void {
    expect(WorkflowsConfig::primaryKeyType()->value)->toBe(env('WORKFLOWS_PRIMARY_KEY_TYPE'));
})->skip(fn (): bool => ! is_string(env('WORKFLOWS_PRIMARY_KEY_TYPE')), 'no key-type leg')->group('real-engine');
