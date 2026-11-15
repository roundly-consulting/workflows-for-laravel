<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('region')->nullable();
            $table->boolean('paused')->default(false);
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('requester_id')->nullable();
            $table->json('custom')->nullable();
            $table->timestamps();
        });
    }
};
