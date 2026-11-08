<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

return new class extends Migration
{
    public function up(): void
    {
        $primary = WorkflowsConfig::primaryKeyType();

        Schema::create(WorkflowsConfig::table('instances'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->char('ulid', 26)->unique('winst_ulid_unique');
            $table->ownerKey('definition_id', $primary, false, false);
            $table->ownerKey('version_id', $primary, false, true);
            $table->morphKey('owner', WorkflowsConfig::ownerKeyType(), true);
            $table->string('subject_key', 100)->nullable();
            $table->morphKey('subject', WorkflowsConfig::subjectKeyType(), true);
            $table->string('trigger_type', 16);
            $table->string('trigger_key', 191)->nullable();
            $table->json('trigger_payload')->nullable();
            $table->char('idempotency_digest', 64)->nullable()->index('winst_idem_idx');
            $table->string('status', 16);
            $table->string('current_node_id', 64)->nullable();
            $table->unsignedInteger('steps_count')->default(0);
            $table->unsignedTinyInteger('depth')->default(0);
            $table->ownerKey('parent_instance_id', $primary, true, true);
            $table->ownerKey('parent_step_run_id', $primary, true, true);
            $table->ownerKey('root_instance_id', $primary, true, true);
            $table->string('actor_mode', 16);
            $table->morphKey('starter', WorkflowsConfig::keyType(), true);
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->string('failed_node_id', 64)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['version_id', 'status', 'current_node_id'], 'winst_overlay_idx');
            $table->index(['definition_id', 'created_at'], 'winst_rate_idx');
            $table->index(['status', 'updated_at'], 'winst_status_idx');
            $table->foreign('definition_id', 'winst_definition_fk')->references('id')->on(WorkflowsConfig::table('definitions'));
            $table->foreign('version_id', 'winst_version_fk')->references('id')->on(WorkflowsConfig::table('versions'));
            $table->foreign('parent_instance_id', 'winst_parent_fk')->references('id')->on(WorkflowsConfig::table('instances'))->nullOnDelete();
        });
    }
};
