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

        Schema::create(WorkflowsConfig::table('versions'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->ownerKey('definition_id', $primary, false, false);
            $table->unsignedInteger('number');
            $table->string('status', 16);
            $table->string('schema_version', 32);
            $table->json('graph');
            $table->json('trigger');
            $table->string('trigger_type', 16);
            $table->string('trigger_key', 191)->nullable();
            $table->string('actor_mode', 16);
            $table->json('settings');
            $table->char('checksum', 64);
            $table->json('review_flags')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->morphKey('publisher', WorkflowsConfig::keyType(), true);
            $table->dateTime('schedule_cursor_at')->nullable();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['definition_id', 'number'], 'wver_number_unique');
            $table->index(['trigger_type', 'trigger_key', 'status'], 'wver_trigger_idx');
            $table->foreign('definition_id', 'wver_definition_fk')->references('id')->on(WorkflowsConfig::table('definitions'))->restrictOnDelete();
        });
    }
};
