<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Ride;
use App\Models\Rider;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two invariants that make double-booking impossible, tested at both the
 * service level (what a client sees) and the database level (what happens if a
 * future code path forgets to check first).
 */
final class ConcurrentAcceptanceTest extends TestCase
{
    #[Test]
    public function only_one_of_two_drivers_can_win_the_same_ride(): void
    {
        $rider = $this->makeRider();
        $fastDriver = $this->makeDriver(['full_name' => 'Fast Driver']);
        $slowDriver = $this->makeDriver(['full_name' => 'Slow Driver']);

        $rideId = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))
            ->json('data.id');

        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($fastDriver))->assertOk();

        // The loser's request arrives second: it must not overwrite the winner.
        $this->postJson("/api/rides/{$rideId}/accept", [], $this->driverHeaders($slowDriver))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RIDE_ALREADY_ACCEPTED')
            ->assertJsonPath('error.details.driverId', $fastDriver->id);

        $ride = Ride::query()->find($rideId);

        $this->assertSame($fastDriver->id, $ride->driver_id);
        $this->assertSame(1, DB::table('driver_assignments')->where('ride_id', $rideId)->count());
        $this->assertSame('on_trip', $fastDriver->fresh()->status->value);
        // The loser was never assigned, so it stays available for other rides.
        $this->assertSame('available', $slowDriver->fresh()->status->value);
    }

    #[Test]
    public function the_database_rejects_a_second_accepted_ride_for_the_same_driver(): void
    {
        $riderA = $this->makeRider();
        $riderB = $this->makeRider();
        $driver = $this->makeDriver();

        $rideA = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($riderA))->json('data.id');
        $this->postJson("/api/rides/{$rideA}/accept", [], $this->driverHeaders($driver))->assertOk();

        $rideB = $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($riderB))->json('data.id');

        // Bypass the service entirely: the partial unique index must still hold.
        try {
            DB::table('rides')->where('id', $rideB)->update(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()]);
            $this->fail('A driver was assigned to two accepted rides');
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            $this->assertTrue(in_array($sqlState, ['23505', '23000'], true));
            $this->assertStringContainsString('rides_one_active_per_driver_idx', $exception->getMessage());
        }
    }

    #[Test]
    public function the_database_rejects_a_second_active_ride_for_the_same_rider(): void
    {
        $rider = $this->makeRider();

        $this->postJson('/api/rides', $this->ridePayload(), $this->riderHeaders($rider))->assertCreated();

        try {
            DB::table('rides')->insert([
                'id' => '33333333-3333-4333-8333-333333333333',
                'rider_id' => $rider->id,
                'status' => 'requested',
                'category' => 'economy',
                'city' => 'Lahore',
                'pickup_label' => 'Gulberg',
                'pickup_lat' => 31.52,
                'pickup_lng' => 74.34,
                'destination_label' => 'DHA',
                'destination_lat' => 31.47,
                'destination_lng' => 74.41,
                'distance_meters' => 8000,
                'duration_seconds' => 900,
                'fare_base_minor' => 16000,
                'fare_distance_minor' => 52000,
                'fare_time_minor' => 18000,
                'fare_minimum_minor' => 26000,
                'surge_multiplier' => 1.0,
                'fare_total_minor' => 86000,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A rider ended up with two live rides');
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            $this->assertTrue(in_array($sqlState, ['23505', '23000'], true));
            $this->assertStringContainsString('rides_one_active_per_rider_idx', $exception->getMessage());
        }
    }

    #[Test]
    public function a_competing_writer_blocks_on_the_ride_row_instead_of_overwriting_it(): void
    {
        /*
         * Real cross-session check of the serialisation primitive: while one
         * transaction holds the ride row, a second session's write to that row
         * must wait (here it trips lock_timeout instead of hanging the suite).
         * That is exactly why accept() re-reads with SELECT ... FOR UPDATE: the
         * loser queues, then observes the winner's committed state.
         *
         * The fixtures are written through the second connection (autocommit) so
         * they are visible to both sessions despite the test-wide transaction.
         */
        $connection = $this->secondConnection();

        $rider = Rider::on('race')->create(['full_name' => 'Race Rider', 'phone' => '+923009999999']);
        $driver = Driver::on('race')->create([
            'full_name' => 'Race Driver',
            'phone' => '+923009999998',
            'city' => 'Lahore',
            'vehicle_category' => 'economy',
            'vehicle_model' => 'Suzuki Alto',
            'vehicle_plate' => 'RCE-0001',
            'rating' => 4.5,
            'status' => 'available',
            'is_active' => true,
            'current_lat' => 31.5204,
            'current_lng' => 74.3440,
        ]);

        $ride = Ride::on('race')->create([
            'rider_id' => $rider->id,
            'status' => 'requested',
            'category' => 'economy',
            'city' => 'Lahore',
            'pickup_label' => 'Gulberg',
            'pickup_lat' => 31.5204,
            'pickup_lng' => 74.3440,
            'destination_label' => 'DHA',
            'destination_lat' => 31.4697,
            'destination_lng' => 74.4123,
            'distance_meters' => 8000,
            'duration_seconds' => 900,
            'fare_base_minor' => 16000,
            'fare_distance_minor' => 52000,
            'fare_time_minor' => 18000,
            'fare_minimum_minor' => 26000,
            'surge_multiplier' => 1.0,
            'fare_total_minor' => 86000,
            'requested_at' => now(),
        ]);

        try {
            DB::beginTransaction();
            DB::table('rides')->where('id', $ride->id)->lockForUpdate()->first();

            $connection->beginTransaction();

            try {
                if (DB::getDriverName() === 'mysql') {
                    $connection->statement('set session innodb_lock_wait_timeout = 1');
                } else {
                    $connection->statement("set local lock_timeout = '250ms'");
                }
                $connection->table('rides')->where('id', $ride->id)->update(['driver_id' => $driver->id]);
                $this->fail('A second session managed to write a locked ride row');
            } catch (QueryException $exception) {
                $err = $exception->errorInfo[0] ?? null;
                $driverCode = $exception->errorInfo[1] ?? null;
                // 55P03 is Postgres lock timeout; 1205 is MySQL lock wait timeout exceeded
                $this->assertTrue($err === '55P03' || $driverCode === 1205, 'expected a lock timeout');
            } finally {
                $connection->rollBack();
            }

            DB::rollBack();
        } finally {
            $connection->table('driver_assignments')->where('ride_id', $ride->id)->delete();
            $connection->table('ride_events')->where('ride_id', $ride->id)->delete();
            $connection->table('rides')->where('id', $ride->id)->delete();
            $connection->table('drivers')->where('id', $driver->id)->delete();
            $connection->table('riders')->where('id', $rider->id)->delete();
        }

        $this->assertNull(Ride::query()->find($ride->id));
    }

    private function secondConnection(): Connection
    {
        $defaultConn = config('database.default');
        config(['database.connections.race' => config("database.connections.{$defaultConn}")]);
        DB::purge('race');

        return DB::connection('race');
    }
}
