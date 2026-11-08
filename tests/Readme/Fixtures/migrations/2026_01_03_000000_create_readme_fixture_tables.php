<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readme_purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignId('requester_id')->nullable();
            $table->foreignId('approver_id')->nullable();
            $table->timestamps();
        });
    }
};
