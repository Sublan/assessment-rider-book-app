<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Enums\RideCategory;
use App\Models\Driver;
use App\Models\Rider;
use Illuminate\Database\Seeder;

/**
 * Deterministic demo data.
 *
 * Fixed UUIDs mean the SPA can remember a rider id between runs, and the backend
 * tests can assert against a known driver. `updateOrCreate` keeps the seeder
 * idempotent: running it twice does not duplicate drivers.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $riders = [
            ['id' => '11111111-1111-4111-8111-111111111111', 'full_name' => 'Ayesha Malik', 'phone' => '+923001234501'],
            ['id' => '11111111-1111-4111-8111-111111111112', 'full_name' => 'Danish Raza', 'phone' => '+923001234502'],
        ];

        foreach ($riders as $rider) {
            Rider::query()->updateOrCreate(['id' => $rider['id']], $rider);
        }

        $drivers = [
            ['id' => '22222222-2222-4222-8222-222222222201', 'full_name' => 'Ali Raza', 'phone' => '+923211111101', 'vehicle_category' => RideCategory::Economy, 'vehicle_model' => 'Suzuki Alto', 'vehicle_plate' => 'LEB-1234', 'rating' => 4.8, 'status' => DriverStatus::Available, 'current_lat' => 31.5200, 'current_lng' => 74.3450],
            ['id' => '22222222-2222-4222-8222-222222222202', 'full_name' => 'Bilal Ahmed', 'phone' => '+923211111102', 'vehicle_category' => RideCategory::Comfort, 'vehicle_model' => 'Toyota Yaris', 'vehicle_plate' => 'LEI-8890', 'rating' => 4.9, 'status' => DriverStatus::Available, 'current_lat' => 31.5167, 'current_lng' => 74.3482],
            ['id' => '22222222-2222-4222-8222-222222222203', 'full_name' => 'Usman Tariq', 'phone' => '+923211111103', 'vehicle_category' => RideCategory::Economy, 'vehicle_model' => 'Suzuki Cultus', 'vehicle_plate' => 'LEC-4412', 'rating' => 4.6, 'status' => DriverStatus::Available, 'current_lat' => 31.4850, 'current_lng' => 74.3250],
            ['id' => '22222222-2222-4222-8222-222222222204', 'full_name' => 'Hamza Sheikh', 'phone' => '+923211111104', 'vehicle_category' => RideCategory::Comfort, 'vehicle_model' => 'Honda City', 'vehicle_plate' => 'LEA-7712', 'rating' => 4.7, 'status' => DriverStatus::Available, 'current_lat' => 31.4697, 'current_lng' => 74.4123],
            ['id' => '22222222-2222-4222-8222-222222222205', 'full_name' => 'Faisal Iqbal', 'phone' => '+923211111105', 'vehicle_category' => RideCategory::Economy, 'vehicle_model' => 'Suzuki Alto', 'vehicle_plate' => 'LED-3321', 'rating' => 4.5, 'status' => DriverStatus::Available, 'current_lat' => 31.4700, 'current_lng' => 74.2730],
            ['id' => '22222222-2222-4222-8222-222222222206', 'full_name' => 'Waqas Khan', 'phone' => '+923211111106', 'vehicle_category' => RideCategory::Economy, 'vehicle_model' => 'Suzuki Wagon R', 'vehicle_plate' => 'LEE-5520', 'rating' => 5.0, 'status' => DriverStatus::Offline, 'current_lat' => 31.4318, 'current_lng' => 74.2424],
            ['id' => '22222222-2222-4222-8222-222222222207', 'full_name' => 'Zeeshan Butt', 'phone' => '+923211111107', 'vehicle_category' => RideCategory::Comfort, 'vehicle_model' => 'Toyota Corolla', 'vehicle_plate' => 'LEF-9987', 'rating' => 4.4, 'status' => DriverStatus::Available, 'current_lat' => 31.5216, 'current_lng' => 74.4036],
            ['id' => '22222222-2222-4222-8222-222222222208', 'full_name' => 'Kamran Akmal', 'phone' => '+923211111108', 'vehicle_category' => RideCategory::Economy, 'vehicle_model' => 'Suzuki Alto', 'vehicle_plate' => 'LEG-1144', 'rating' => 4.3, 'status' => DriverStatus::Available, 'current_lat' => 31.3667, 'current_lng' => 74.1833],
        ];

        foreach ($drivers as $driver) {
            Driver::query()->updateOrCreate(
                ['id' => $driver['id']],
                [
                    ...$driver,
                    'city' => 'Lahore',
                    'is_active' => true,
                    'vehicle_category' => $driver['vehicle_category']->value,
                    'status' => $driver['status']->value,
                ],
            );
        }
    }
}
