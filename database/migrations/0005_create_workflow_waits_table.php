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

        Schema::create(WorkflowsConfig::table('waits'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->ownerKey('instance_id', $primary, false, true);
            $table->ownerKey('step_run_id', $primary, false, true);
            $table->string('kind', 16);
            $table->dateTime('due_at')->nullable();
            $table->string('event_key', 191)->nullable();
            $table->json('match')->nullable();
            $table->json('condition')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16);
            $table->string('claim_token', 26)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('fired_at')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['status', 'due_at'], 'wwait_due_idx');
            $table->index(['status', 'event_key'], 'wwait_event_idx');
            $table->foreign('instance_id', 'wwait_instance_fk')->references('id')->on(WorkflowsConfig::table('instances'))->cascadeOnDelete();
            $table->foreign('step_run_id', 'wwait_step_fk')->references('id')->on(WorkflowsConfig::table('step_runs'))->cascadeOnDelete();
        });
    }
};
