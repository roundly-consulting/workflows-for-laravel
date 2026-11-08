<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('title')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('state')->nullable();
            $table->unsignedBigInteger('requester_id')->nullable();
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
