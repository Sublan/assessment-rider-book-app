<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_assignments', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('ride_id')->unique();
            $table->uuid('driver_id');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();

            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers');
            $table->index(['driver_id', 'assigned_at']);
        });

        Schema::create('ride_events', function ($table) {
            $table->bigIncrements('id');
            $table->uuid('ride_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 32);
            $table->uuid('actor_id')->nullable();
            $table->json('metadata');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ride_id')->references('id')->on('rides')->cascadeOnDelete();
            $table->index(['ride_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_events');
        Schema::dropIfExists('driver_assignments');
    }
};
