<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ride;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RideLifecycleTest extends TestCase
{
    #[Test]
    public function the_happy_path_is_requested_accepted_completed(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->getJson("/api/rides/{$rideId}")->assertOk()->assertJsonPath('data.status', 'requested');
        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))->assertOk();
        $this->postJson("/api/rides/{$rideId}/complete", [], $this->driverHeaders($driver))->assertOk();

        $this->getJson("/api/rides/{$rideId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.cancellable', false)
            ->assertJsonCount(3, 'data.timeline');

        $this->assertSame('available', $driver->fresh()->status->value);
        $this->assertNotNull(Ride::query()->find($rideId)->completed_at);
    }

    #[Test]
    public function a_rider_can_cancel_before_a_driver_accepts(): void
    {
        $rider = $this->makeRider();
        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/cancel", ['reason' => 'Changed my mind'], $this->riderHeaders($rider))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelledBy', 'rider')
            ->assertJsonPath('data.cancellationReason', 'Changed my mind');
    }

    #[Test]
    public function cancelling_an_accepted_ride_frees_the_driver(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');
        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))->assertOk();

        $this->postJson("/api/rides/{$rideId}/cancel", [], $this->riderHeaders($rider))->assertOk();

        $this->assertSame('available', $driver->fresh()->status->value);
        $this->assertDatabaseHas('driver_assignments', ['ride_id' => $rideId, 'end_reason' => 'cancelled']);
    }

    #[Test]
    public function the_assigned_driver_can_cancel(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');
        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))->assertOk();

        $this->postJson("/api/rides/{$rideId}/cancel", ['reason' => 'Car trouble'], $this->driverHeaders($driver))
            ->assertOk()
            ->assertJsonPath('data.cancelledBy', 'driver');
    }

    #[Test]
    public function another_rider_cannot_cancel_someone_elses_ride(): void
    {
        $rider = $this->makeRider();
        $stranger = $this->makeRider();

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/cancel", [], $this->riderHeaders($stranger))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->getJson("/api/rides/{$rideId}")->assertJsonPath('data.status', 'requested');
    }

    #[Test]
    public function a_completed_ride_cannot_be_cancelled(): void
    {
        [$rideId, $driver] = $this->acceptedRide();
        $this->postJson("/api/rides/{$rideId}/complete", [], $this->driverHeaders($driver))->assertOk();

        $this->postJson("/api/rides/{$rideId}/cancel", [], $this->driverHeaders($driver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    #[Test]
    public function a_cancelled_ride_cannot_be_accepted(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();
        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/cancel", [], $this->riderHeaders($rider))->assertOk();

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    #[Test]
    public function a_ride_cannot_be_completed_before_it_is_accepted(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();
        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/complete", [], $this->driverHeaders($driver))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function only_the_assigned_driver_can_complete_a_ride(): void
    {
        [$rideId, $assigned] = $this->acceptedRide();
        $other = $this->makeDriver();

        $this->postJson("/api/rides/{$rideId}/complete", [], $this->driverHeaders($other))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->postJson("/api/rides/{$rideId}/complete", [], $this->driverHeaders($assigned))->assertOk();
    }

    #[Test]
    public function cancelling_twice_is_rejected(): void
    {
        $rider = $this->makeRider();
        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');

        $this->postJson("/api/rides/{$rideId}/cancel", [], $this->riderHeaders($rider))->assertOk();

        $this->postJson("/api/rides/{$rideId}/cancel", [], $this->riderHeaders($rider))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    #[Test]
    public function an_unknown_ride_returns_a_404_envelope(): void
    {
        $this->getJson('/api/rides/00000000-0000-4000-8000-000000000001')
            ->assertNotFound()
            ->assertJsonStructure(['error' => ['code', 'message', 'requestId']])
            ->assertJsonPath('error.code', 'RIDE_NOT_FOUND');
    }

    /** @return array{0: string, 1: \App\Models\Driver} */
    private function acceptedRide(): array
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->json('data.id');
        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))->assertOk();

        return [$rideId, $driver];
    }
}
