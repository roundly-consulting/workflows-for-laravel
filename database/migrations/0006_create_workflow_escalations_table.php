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

        Schema::create(WorkflowsConfig::table('escalations'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->ownerKey('instance_id', $primary, false, true);
            $table->ownerKey('step_run_id', $primary, false, true);
            $table->unsignedSmallInteger('level');
            $table->dateTime('due_at');
            $table->json('actions');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('claim_token', 26)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('fired_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['status', 'due_at'], 'wesc_due_idx');
            $table->foreign('instance_id', 'wesc_instance_fk')->references('id')->on(WorkflowsConfig::table('instances'))->cascadeOnDelete();
            $table->foreign('step_run_id', 'wesc_step_fk')->references('id')->on(WorkflowsConfig::table('step_runs'))->cascadeOnDelete();
        });
    }
};
