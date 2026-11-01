<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('rider_id');
            $table->string('status', 32)->default('requested');
            $table->string('category', 32);
            $table->string('city', 64)->default('Lahore');

            $table->string('pickup_label', 255);
            $table->double('pickup_lat');
            $table->double('pickup_lng');
            $table->string('destination_label', 255);
            $table->double('destination_lat');
            $table->double('destination_lng');

            $table->integer('distance_meters');
            $table->integer('duration_seconds');
            $table->integer('pickup_eta_minutes')->nullable();

            // Fare snapshot. Stored at request time so a price change never
            // rewrites history; all values are integer paisa.
            $table->string('currency', 3)->default('PKR');
            $table->integer('fare_base_minor');
            $table->integer('fare_distance_minor');
            $table->integer('fare_time_minor');
            $table->integer('fare_minimum_minor');
            $table->decimal('surge_multiplier', 3, 2)->default(1.0);
            $table->integer('fare_total_minor');

            $table->uuid('driver_id')->nullable();

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 32)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('rider_id')->references('id')->on('riders');
            $table->foreign('driver_id')->references('id')->on('drivers');
            $table->index(['rider_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Unique functional index to enforce partial uniqueness in MySQL 8.0+
        // A rider can have only one live ride ('requested' or 'accepted')
        // Multiple NULLs are allowed in MySQL unique indexes!
        DB::statement("
            create unique index rides_one_active_per_rider_idx
                on rides ((case when status in ('requested', 'accepted') then rider_id else null end))
        ");
        // A driver can be bound to only one 'accepted' ride
        DB::statement("
            create unique index rides_one_active_per_driver_idx
                on rides ((case when status = 'accepted' then driver_id else null end))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
