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

        Schema::create(WorkflowsConfig::table('definitions'), function (Blueprint $table) use ($primary): void {
            match ($primary) {
                KeyType::BigInt => $table->id(),
                KeyType::Uuid => $table->uuid('id')->primary(),
                KeyType::Ulid => $table->ulid('id')->primary(),
            };

            $table->string('key', 100);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->morphKey('owner', WorkflowsConfig::ownerKeyType(), true);
            // `type:id` of the owner, or `*` for an app-wide definition: one unique key per owner.
            $table->string('owner_scope', 191);
            $table->string('subject_key', 100)->nullable();
            $table->string('status', 16);
            // No FK: definitions <-> versions would be circular; held consistent under the definition lock.
            $table->ownerKey('current_version_id', $primary, true, true);
            $table->string('webhook_id', 26)->nullable()->unique('wdef_hook_unique');
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['owner_scope', 'key'], 'wdef_key_unique');
        });
    }
};
