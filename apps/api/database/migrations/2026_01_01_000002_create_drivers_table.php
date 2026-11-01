<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function ($table) {
            $table->uuid('id')->primary();
            $table->string('full_name', 120);
            $table->string('phone', 32)->unique();
            $table->string('city', 64)->default('Lahore');
            // available | on_trip | offline
            $table->string('vehicle_category', 32);
            $table->string('vehicle_model', 64);
            $table->string('vehicle_plate', 32);
            $table->decimal('rating', 2, 1)->default(5.0);
            $table->string('status', 32)->default('offline');
            $table->boolean('is_active')->default(true);
            $table->double('current_lat')->nullable();
            $table->double('current_lng')->nullable();
            $table->timestamps();

            $table->index(['city', 'vehicle_category', 'status', 'is_active'], 'drivers_city_cat_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
