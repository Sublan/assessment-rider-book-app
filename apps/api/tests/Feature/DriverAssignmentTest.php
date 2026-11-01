<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\Ride;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DriverAssignmentTest extends TestCase
{
    #[Test]
    public function accepting_a_ride_assigns_the_driver_and_marks_them_on_trip(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))
            ->json('data.id');

        $response = $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver));

        $response->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.driver.id', $driver->id)
            ->assertJsonPath('data.driver.vehiclePlate', 'LEB-0000');

        $this->assertSame(DriverStatus::OnTrip, $driver->fresh()->status);
        $this->assertDatabaseHas('driver_assignments', [
            'ride_id' => $rideId,
            'driver_id' => $driver->id,
            'ended_at' => null,
        ]);
        $this->assertNotNull(Ride::query()->find($rideId)->accepted_at);
    }

    #[Test]
    public function an_offline_driver_cannot_accept(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver(['status' => DriverStatus::Offline->value]);

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
    }

    #[Test]
    public function an_an_economy_vehicle_cannot_serve_a_comfort_ride(): void
    {
        $rider = $this->makeRider();
        $economyDriver = $this->makeDriver(['vehicle_category' => 'economy']);

        $rideId = $this->postJson('/api/rides', $this->ridePayload(['category' => 'comfort']), $this->riderHeaders($rider))
            ->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($economyDriver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
    }

    #[Test]
    public function a_comfort_vehicle_can_serve_an_economy_ride(): void
    {
        $rider = $this->makeRider();
        $comfortDriver = $this->makeDriver(['vehicle_category' => 'comfort', 'vehicle_model' => 'Toyota Yaris']);

        $rideId = $this->postJson('/api/rides', $this->ridePayload(['category' => 'economy']), $this->riderHeaders($rider))
            ->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($comfortDriver))
            ->assertOk()
            ->assertJsonPath('data.driver.id', $comfortDriver->id);
    }

    #[Test]
    public function an_inactive_driver_cannot_accept(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver(['is_active' => false]);

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');
    }

    #[Test]
    public function a_driver_already_on_a_trip_cannot_accept_another_ride(): void
    {
        $riderA = $this->makeRider();
        $riderB = $this->makeRider();
        $driver = $this->makeDriver();

        $rideA = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($riderA))->json('data.id');
        $rideB = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($riderB))->json('data.id');

        $this->postJson("/api/rides/{$rideA}/accept", [], $this->driverHeaders($driver))->assertOk();

        $this->postJson("/api/rides/{$rideB}/accept", [], $this->driverHeaders($driver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DRIVER_BUSY');
    }

    #[Test]
    public function accepting_an_unknown_ride_returns_404(): void
    {
        $driver = $this->makeDriver();

        $this->postJson('/api/rides/00000000-0000-4000-8000-000000000000/accept', [], $this->driverHeaders($driver))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RIDE_NOT_FOUND');
    }

    #[Test]
    public function accepting_requires_a_driver_identity(): void
    {
        $rider = $this->makeRider();
        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'MISSING_ACTOR');
    }

    #[Test]
    public function a_driver_can_only_change_their_own_availability(): void
    {
        $driver = $this->makeDriver();
        $other = $this->makeDriver();

        $this->patchJson("/api/drivers/{$other->id}/status", ['status' => 'offline'], $this->driverHeaders($driver))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function a_driver_can_go_online_with_a_location(): void
    {
        $driver = $this->makeDriver(['status' => DriverStatus::Offline->value]);

        $this->patchJson("/api/drivers/{$driver->id}/status", [
            'status' => 'available',
            'lat' => 31.52,
            'lng' => 74.34,
        ], $this->driverHeaders($driver))
            ->assertOk()
            ->assertJsonPath('data.status', 'available');

        $this->assertSame(DriverStatus::Available, $driver->fresh()->status);
    }

    #[Test]
    public function a_driver_cannot_go_online_without_a_location(): void
    {
        $driver = $this->makeDriver(['status' => DriverStatus::Offline->value, 'current_lat' => null, 'current_lng' => null]);

        $this->patchJson("/api/drivers/{$driver->id}/status", ['status' => 'available'], $this->driverHeaders($driver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DRIVER_NOT_ELIGIBLE');

        $this->assertSame(DriverStatus::Offline, Driver::query()->find($driver->id)->status);
    }
}
