<?php

declare(strict_types=1);

it('refuses to install over existing tables', function (): void {
    $this->artisan('workflows:install')->expectsOutputToContain('already exists')->assertFailed();

    expect(glob(config_path('*')))->toBe([]);
});

it('publishes the config and the migrations into a fresh app', function (): void {
    foreach (['definitions', 'versions', 'instances', 'step_runs', 'waits', 'escalations', 'idempotency_keys', 'dry_runs'] as $table) {
        config()->set('workflows.tables.'.$table, 'fresh_'.$table);
    }

    $this->artisan('workflows:install')->expectsOutputToContain("Schedule::command('workflows:wake')")->assertSuccessful();

    expect(config_path('workflows.php'))->toBeFile()
        ->and(glob(database_path('migrations/*.php')))->toHaveCount(8)
        ->and(glob(database_path('migrations/*_create_workflow_definitions_table.php')))->toHaveCount(1);
});
