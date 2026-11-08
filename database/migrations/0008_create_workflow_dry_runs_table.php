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

        Schema::create(WorkflowsConfig::table('dry_runs'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->ownerKey('definition_id', $primary, false, true);
            $table->ownerKey('version_id', $primary, true, true);
            $table->morphKey('owner', WorkflowsConfig::ownerKeyType(), true);
            $table->morphKey('subject', WorkflowsConfig::subjectKeyType(), true);
            $table->json('input')->nullable();
            $table->json('result');
            $table->morphKey('requester', WorkflowsConfig::keyType(), true);
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->foreign('definition_id', 'wdry_definition_fk')->references('id')->on(WorkflowsConfig::table('definitions'))->cascadeOnDelete();
            $table->foreign('version_id', 'wdry_version_fk')->references('id')->on(WorkflowsConfig::table('versions'))->nullOnDelete();
        });
    }
};
