<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ride;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    #[Test]
    public function retrying_a_create_with_the_same_key_returns_the_original_ride(): void
    {
        $rider = $this->makeRider();
        $headers = $this->riderHeaders($rider) + ['Idempotency-Key' => 'create-ride-key-0001'];

        $first = $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();
        $second = $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('false', $first->headers->get('Idempotent-Replayed'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));

        // Exactly one ride exists: the retry did not create a second one and did
        // not trip the "one active ride per rider" rule either.
        $this->assertSame(1, Ride::query()->count());
    }

    #[Test]
    public function ten_retries_of_the_same_key_produce_one_ride(): void
    {
        $rider = $this->makeRider();
        $headers = $this->riderHeaders($rider) + ['Idempotency-Key' => 'create-ride-key-0002'];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();
        }

        $this->assertSame(1, Ride::query()->count());
    }

    #[Test]
    public function reusing_a_key_with_a_different_body_is_rejected(): void
    {
        $rider = $this->makeRider();
        $headers = $this->riderHeaders($rider) + ['Idempotency-Key' => 'create-ride-key-0003'];

        $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();

        $this->postJson('/api/rides', $this->ridePayload(['category' => 'comfort']), $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    #[Test]
    public function a_short_idempotency_key_is_rejected(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider) + ['Idempotency-Key' => 'abc'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function the_same_key_can_be_used_by_a_different_rider(): void
    {
        // Keys are scoped per endpoint and hashed together with the caller, so one
        // client cannot collide with another client's key.
        $riderA = $this->makeRider();
        $riderB = $this->makeRider();

        $first = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($riderA) + ['Idempotency-Key' => 'shared-key-0001'])->assertCreated();
        $second = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($riderB) + ['Idempotency-Key' => 'shared-key-0001'])->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, Ride::query()->count());
    }

    #[Test]
    public function a_failed_request_does_not_poison_the_key(): void
    {
        $rider = $this->makeRider();
        $headers = $this->riderHeaders($rider) + ['Idempotency-Key' => 'create-ride-key-0004'];

        // First attempt is rejected for being outside the service area, so nothing
        // is written and the claim is rolled back with it.
        $this->postJson('/api/rides', $this->ridePayload([
            'pickup' => ['label' => 'Karachi', 'lat' => 24.8607, 'lng' => 67.0011],
        ]), $headers)->assertStatus(422);

        $this->assertSame(0, Ride::query()->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());

        // The same key now succeeds with a valid body.
        $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();
        $this->assertSame(1, Ride::query()->count());
    }

    #[Test]
    public function a_duplicate_without_a_key_is_still_blocked(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->assertCreated();

        // No Idempotency-Key: the "one live ride per rider" rule is the backstop.
        $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACTIVE_RIDE_EXISTS');
    }

    #[Test]
    public function the_snapshot_is_returned_even_after_the_ride_changes_state(): void
    {
        $rider = $this->makeRider();
        $driver = $this->makeDriver();
        $headers = $this->riderHeaders($rider) + ['Idempotency-Key' => 'create-ride-key-0005'];

        $created = $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();
        $rideId = $created->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($driver))->assertOk();

        // The replay answers the original request: 201 + the ride as it was then.
        $replayed = $this->postJson('/api/rides', $this->ridePayload(), $headers)->assertCreated();
        $this->assertSame($rideId, $replayed->json('data.id'));
        $this->assertSame('requested', $replayed->json('data.status'));

        // The live resource still shows the current state.
        $this->getJson("/api/rides/{$rideId}")->assertOk()->assertJsonPath('data.status', 'accepted');
    }
}
