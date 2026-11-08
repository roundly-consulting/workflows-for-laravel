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

        Schema::create(WorkflowsConfig::table('step_runs'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->ownerKey('instance_id', $primary, false, false);
            $table->string('node_id', 64);
            $table->string('node_type', 64);
            $table->unsignedInteger('visit');
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);
            $table->string('idempotency_key', 100);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            // The signal a resume claimed the step with; an automatic retry of that attempt resumes with it.
            $table->json('resume_signal')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('error_retryable')->nullable();
            $table->string('claim_token', 26)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('scheduled_for')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            // A waiting step's probe is pushed and not yet claimed (wake re-pushes a lost one).
            $table->dateTime('probe_pushed_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['instance_id', 'node_id', 'visit', 'attempt'], 'wstep_attempt_unique');
            $table->index(['status', 'lease_until'], 'wstep_lease_idx');
            $table->index(['status', 'updated_at'], 'wstep_queued_idx');
            $table->index(['status', 'probe_pushed_at'], 'wstep_probe_idx');
            $table->foreign('instance_id', 'wstep_instance_fk')->references('id')->on(WorkflowsConfig::table('instances'))->cascadeOnDelete();
        });
    }
};
