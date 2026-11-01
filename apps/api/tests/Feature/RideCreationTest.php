<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RideStatus;
use App\Models\Ride;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RideCreationTest extends TestCase
{
    #[Test]
    public function it_creates_a_requested_ride_with_a_fare_snapshot(): void
    {
        $rider = $this->makeRider();
        $this->makeDriver();

        $response = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.category', 'economy')
            ->assertJsonPath('data.riderId', $rider->id)
            ->assertJsonPath('data.driver', null)
            ->assertJsonPath('data.cancellable', true)
            ->assertJsonPath('data.fare.currency', 'PKR')
            ->assertJsonPath('data.pickup.label', 'Gulberg III, Main Boulevard');

        $ride = Ride::query()->sole();

        $this->assertSame(RideStatus::Requested, $ride->status);
        $this->assertGreaterThan(0, $ride->fare_total_minor);
        $this->assertSame($ride->fare_base_minor + $ride->fare_distance_minor + $ride->fare_time_minor > 0, true);

        // The fare the rider saw is persisted, not recomputed on read.
        $response->assertJsonPath('data.fare.totalMinor', $ride->fare_total_minor);
        $response->assertJsonPath('data.trip.distanceMeters', $ride->distance_meters);
    }

    #[Test]
    public function it_records_a_requested_event_in_the_timeline(): void
    {
        $rider = $this->makeRider();

        $response = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider));

        $response->assertCreated()
            ->assertJsonPath('data.timeline.0.type', 'ride_requested')
            ->assertJsonPath('data.timeline.0.actorType', 'rider');
    }

    #[Test]
    public function it_rejects_an_unknown_category(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload(['category' => 'helicopter']), $this->riderHeaders($rider))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['category'], 'requestId']]);
    }

    #[Test]
    public function it_rejects_missing_coordinates(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', [
            'category' => 'economy',
            'pickup' => ['label' => 'Gulberg'],
            'destination' => ['label' => 'DHA'],
        ], $this->riderHeaders($rider))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function it_requires_a_rider_identity(): void
    {
        $this->postJson('/api/rides', $this->ridePayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'MISSING_ACTOR');
    }

    #[Test]
    public function it_rejects_a_malformed_rider_header(): void
    {
        $this->postJson('/api/rides', $this->ridePayload(), ['x-rider-id' => 'not-a-uuid'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function it_rejects_an_unknown_rider(): void
    {
        $this->postJson('/api/rides', $this->ridePayload(), ['x-rider-id' => '99999999-9999-4999-8999-999999999999'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RIDER_NOT_FOUND');
    }

    #[Test]
    public function it_rejects_a_pickup_outside_lahore(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload([
            'pickup' => ['label' => 'Karachi', 'lat' => 24.8607, 'lng' => 67.0011],
        ]), $this->riderHeaders($rider))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OUTSIDE_SERVICE_AREA');

        $this->assertSame(0, Ride::query()->count());
    }

    #[Test]
    public function it_rejects_a_trip_where_pickup_equals_destination(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload([
            'destination' => ['label' => 'Same place', 'lat' => 31.5204, 'lng' => 74.3440],
        ]), $this->riderHeaders($rider))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRIP_TOO_SHORT');
    }

    #[Test]
    public function it_rejects_a_second_active_ride_for_the_same_rider(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->assertCreated();

        $second = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider));

        $second->assertStatus(409)->assertJsonPath('error.code', 'ACTIVE_RIDE_EXISTS');
        $this->assertSame(1, Ride::query()->count());
        $this->assertSame(Ride::query()->sole()->id, $second->json('error.details.rideId'));
    }

    #[Test]
    public function it_allows_a_new_ride_after_the_previous_one_is_cancelled(): void
    {
        $rider = $this->makeRider();

        $first = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->assertCreated();
        $this->postJson("/api/rides/{$first->json('data.id')}/cancel", [], $this->riderHeaders($rider))->assertOk();

        $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->assertCreated();
        $this->assertSame(2, Ride::query()->count());
    }

    #[Test]
    public function it_reports_no_available_drivers_without_blocking_the_request(): void
    {
        $rider = $this->makeRider();

        $response = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider));

        $response->assertCreated()->assertJsonPath('data.trip.pickupEtaMinutes', null);
    }
}
