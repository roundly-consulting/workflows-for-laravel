<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Workflows\Support\WorkflowsConfig;

/**
 * 1.4: the structured details a failing step may carry, on the attempt and on the instance it
 * failed. Additive and nullable: every earlier failure reads `details: null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(WorkflowsConfig::table('step_runs'), function (Blueprint $table): void {
            $table->json('error_details')->nullable()->after('error_retryable');
        });

        Schema::table(WorkflowsConfig::table('instances'), function (Blueprint $table): void {
            $table->json('failure_details')->nullable()->after('failed_node_id');
        });
    }
};
