<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\DriverStatus;
use App\Enums\RideCategory;
use App\Models\Driver;
use App\Models\Rider;

trait RideFixtures
{
    private static int $phoneCounter = 0;

    /** @param array<string, mixed> $overrides */
    protected function makeRider(array $overrides = []): Rider
    {
        return Rider::create([
            'full_name' => 'Test Rider',
            'phone'     => $this->uniquePhone(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function makeDriver(array $overrides = []): Driver
    {
        return Driver::create([
            'full_name'        => 'Test Driver',
            'phone'            => $this->uniquePhone(),
            'city'             => 'Lahore',
            'vehicle_category' => RideCategory::Economy->value,
            'vehicle_model'    => 'Suzuki Alto',
            'vehicle_plate'    => 'LEB-0000',
            'rating'           => 4.5,
            'status'           => DriverStatus::Available->value,
            'is_active'        => true,
            'current_lat'      => 31.5204,
            'current_lng'      => 74.3440,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    protected function ridePayload(array $overrides = []): array
    {
        return [
            'category'    => 'economy',
            'pickup'      => ['label' => 'Gulberg III, Main Boulevard', 'lat' => 31.5204, 'lng' => 74.3440],
            'destination' => ['label' => 'DHA Phase 5, Khayaban-e-Bukhari', 'lat' => 31.4697, 'lng' => 74.4123],
            ...$overrides,
        ];
    }

    /**
     * Returns Authorization header carrying a Sanctum Bearer token for this rider.
     * Tests call this instead of setting raw X-Rider-Id headers.
     *
     * @return array<string, string>
     */
    protected function riderHeaders(Rider $rider): array
    {
        $token = $rider->createToken('test-rider', ['role:rider'])->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Returns Authorization header carrying a Sanctum Bearer token for this driver.
     *
     * @return array<string, string>
     */
    protected function driverHeaders(Driver $driver): array
    {
        $token = $driver->createToken('test-driver', ['role:driver'])->plainTextToken;

        return ['Authorization' => "Bearer {$token}"];
    }

    private function uniquePhone(): string
    {
        self::$phoneCounter++;

        return sprintf('+92300%07d', self::$phoneCounter);
    }
}
