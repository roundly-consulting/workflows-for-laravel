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

        // A ledger: no soft deletes (the documented exception). Effect keys and engine-internal
        // start keys never expire and go with their instance tree.
        Schema::create(WorkflowsConfig::table('idempotency_keys'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->string('scope', 16);
            $table->char('key', 64);
            $table->ownerKey('definition_id', $primary, true, true);
            $table->ownerKey('instance_id', $primary, true, true);
            $table->json('result')->nullable();
            $table->dateTime('expires_at')->nullable()->index('widem_expires_idx');
            $table->dateTime('created_at')->nullable();

            $table->unique(['scope', 'key'], 'widem_unique');
            $table->foreign('definition_id', 'widem_definition_fk')->references('id')->on(WorkflowsConfig::table('definitions'))->nullOnDelete();
            $table->foreign('instance_id', 'widem_instance_fk')->references('id')->on(WorkflowsConfig::table('instances'))->cascadeOnDelete();
        });
    }
};
