<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideCategory;
use App\Enums\RideStatus;
use App\Events\RideUpdated;
use App\Exceptions\ApiException;
use App\Http\Presenters\RidePresenter;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Ride;
use App\Models\RideEvent;
use App\Models\Rider;
use App\Support\RideStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The ride lifecycle. Everything that mutates a ride lives here so that HTTP,
 * persistence and the transition rules cannot drift apart.
 *
 * Two rules make double-booking impossible:
 *   1. every mutation re-reads the ride inside a transaction with SELECT ... FOR
 *      UPDATE, so a concurrent writer waits and then sees the new state;
 *   2. the database itself enforces the invariants with partial unique indexes
 *      (one live ride per rider, one accepted ride per driver), so a bug in the
 *      service cannot create a double booking either.
 */
final class RideService
{
    private const IDEMPOTENCY_SCOPE = 'rides.create';

    public function __construct(
        private readonly TripPlanner $planner,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * @param  array{category: string, pickup: array{label: string, lat: float, lng: float}, destination: array{label: string, lat: float, lng: float}}  $input
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function create(array $input, string $riderId, ?string $idempotencyKey = null): array
    {
        $category = RideCategory::from($input['category']);
        $city = (string) config('ride.default_city');

        $requestHash = $this->idempotency->fingerprint([
            'riderId' => $riderId,
            'category' => $input['category'],
            'pickup' => $input['pickup'],
            'destination' => $input['destination'],
        ]);

        // Keys are namespaced per rider: one client's key can never collide with
        // (or be squatted on by) another client's key.
        $scope = self::IDEMPOTENCY_SCOPE.':'.$riderId;

        if ($idempotencyKey !== null) {
            $cached = $this->idempotency->replay($idempotencyKey, $scope, $requestHash);
            if ($cached !== null) {
                return ['status' => $cached['status'], 'body' => $cached['body'], 'replayed' => true];
            }
        }

        // Pure computation first: keeps the transaction short and means an
        // invalid trip never takes a lock.
        $plan = $this->planner->plan(
            $category,
            (float) $input['pickup']['lat'],
            (float) $input['pickup']['lng'],
            (float) $input['destination']['lat'],
            (float) $input['destination']['lng'],
            $city,
        );

        try {
            $body = DB::transaction(function () use ($input, $riderId, $idempotencyKey, $requestHash, $scope, $category, $city, $plan): array {
                if ($idempotencyKey !== null) {
                    $this->idempotency->claim($idempotencyKey, $scope, $requestHash);
                }

                if (! Rider::query()->whereKey($riderId)->exists()) {
                    throw ApiException::riderNotFound($riderId);
                }

                $active = Ride::query()
                    ->where('rider_id', $riderId)
                    ->whereIn('status', RideStatus::activeValues())
                    ->lockForUpdate()
                    ->first();

                if ($active !== null) {
                    throw ApiException::activeRideExists($active->id);
                }

                $ride = Ride::create([
                    'rider_id' => $riderId,
                    'status' => RideStatus::Requested->value,
                    'category' => $category->value,
                    'city' => $city,
                    'pickup_label' => $input['pickup']['label'],
                    'pickup_lat' => $input['pickup']['lat'],
                    'pickup_lng' => $input['pickup']['lng'],
                    'destination_label' => $input['destination']['label'],
                    'destination_lat' => $input['destination']['lat'],
                    'destination_lng' => $input['destination']['lng'],
                    'distance_meters' => $plan['distanceMeters'],
                    'duration_seconds' => $plan['durationSeconds'],
                    'pickup_eta_minutes' => $plan['pickupEtaMinutes'],
                    'currency' => $plan['fare']['currency'],
                    'fare_base_minor' => $plan['fare']['base_minor'],
                    'fare_distance_minor' => $plan['fare']['distance_minor'],
                    'fare_time_minor' => $plan['fare']['time_minor'],
                    'fare_minimum_minor' => $plan['fare']['minimum_minor'],
                    'surge_multiplier' => $plan['fare']['surge_multiplier'],
                    'fare_total_minor' => $plan['fare']['total_minor'],
                    'requested_at' => now(),
                ]);

                $this->recordEvent($ride, 'ride_requested', 'rider', $riderId, [
                    'category' => $category->value,
                    'availableDrivers' => $plan['availableDrivers'],
                ]);

                $ride->load(['driver', 'events']);
                $body = ['data' => RidePresenter::present($ride)];

                if ($idempotencyKey !== null) {
                    $this->idempotency->remember($idempotencyKey, $scope, 201, $body, $ride->id);
                }

                RideUpdated::dispatch($ride);

                return $body;
            });
        } catch (ReplayResponse $replay) {
            return ['status' => $replay->status, 'body' => $replay->body, 'replayed' => true];
        } catch (QueryException $exception) {
            throw $this->translateUniqueViolation($exception, $riderId);
        }

        return ['status' => 201, 'body' => $body, 'replayed' => false];
    }

    public function find(string $rideId): Ride
    {
        $ride = Ride::query()->with(['driver', 'events'])->whereKey($rideId)->first();

        return $ride ?? throw ApiException::rideNotFound($rideId);
    }

    /**
     * Assign a driver to a requested ride.
     *
     * The ride row is locked first: two drivers racing for the same ride are
     * serialised, and the loser sees status=accepted and gets 409
     * RIDE_ALREADY_ACCEPTED instead of overwriting the winner's assignment.
     */
    public function accept(string $rideId, string $driverId): Ride
    {
        try {
            return DB::transaction(function () use ($rideId, $driverId): Ride {
                $ride = Ride::query()->whereKey($rideId)->lockForUpdate()->first()
                    ?? throw ApiException::rideNotFound($rideId);

                $driver = Driver::query()->whereKey($driverId)->lockForUpdate()->first()
                    ?? throw ApiException::driverNotFound($driverId);

                if ($ride->status === RideStatus::Accepted) {
                    throw ApiException::rideAlreadyAccepted($ride->driver_id);
                }

                RideStateMachine::assertCanTransition($ride->status, RideStatus::Accepted);
                $this->assertDriverEligible($driver, $ride);

                $updated = Ride::query()
                    ->where('id', $rideId)
                    ->where('status', RideStatus::Requested->value)
                    ->update([
                        'status' => RideStatus::Accepted->value,
                        'driver_id' => $driverId,
                        'accepted_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    // Another transaction won between our lock and our update.
                    throw ApiException::rideAlreadyAccepted();
                }

                $this->setDriverStatus($driverId, DriverStatus::OnTrip);

                DriverAssignment::create([
                    'ride_id' => $rideId,
                    'driver_id' => $driverId,
                    'assigned_at' => now(),
                ]);

                $ride->refresh();
                $this->recordEvent($ride, 'ride_accepted', 'driver', $driverId, [
                    'vehicleModel' => $driver->vehicle_model,
                    'vehiclePlate' => $driver->vehicle_plate,
                ]);

                $loaded = $ride->load(['driver', 'events']);
                RideUpdated::dispatch($loaded);

                return $loaded;
            });
        } catch (QueryException $exception) {
            throw $this->translateUniqueViolation($exception, null, $driverId);
        }
    }

    public function cancel(string $rideId, ?string $riderId, ?string $driverId, ?string $reason = null): Ride
    {
        return DB::transaction(function () use ($rideId, $riderId, $driverId, $reason): Ride {
            $ride = Ride::query()->whereKey($rideId)->lockForUpdate()->first()
                ?? throw ApiException::rideNotFound($rideId);

            $cancelledBy = $this->resolveCancellingActor($ride, $riderId, $driverId);

            RideStateMachine::assertCanTransition($ride->status, RideStatus::Cancelled);
            $fromStatus = $ride->status->value;

            $ride->fill([
                'status' => RideStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy,
                'cancellation_reason' => $reason,
            ])->save();

            $this->releaseDriver($ride, 'cancelled');
            $this->recordEvent($ride, 'ride_cancelled', $cancelledBy, $cancelledBy === 'rider' ? $riderId : $driverId, [
                'reason' => $reason,
                'fromStatus' => $fromStatus,
            ]);

            $loaded = $ride->load(['driver', 'events']);
            RideUpdated::dispatch($loaded);

            return $loaded;
        });
    }

    public function complete(string $rideId, string $driverId): Ride
    {
        return DB::transaction(function () use ($rideId, $driverId): Ride {
            $ride = Ride::query()->whereKey($rideId)->lockForUpdate()->first()
                ?? throw ApiException::rideNotFound($rideId);

            if ($ride->driver_id !== $driverId) {
                throw ApiException::forbidden('Only the assigned driver can complete this ride');
            }

            RideStateMachine::assertCanTransition($ride->status, RideStatus::Completed);

            $ride->fill([
                'status' => RideStatus::Completed->value,
                'completed_at' => now(),
            ])->save();

            $this->releaseDriver($ride, 'completed');
            $this->recordEvent($ride, 'ride_completed', 'driver', $driverId, [
                'fareTotalMinor' => $ride->fare_total_minor,
            ]);

            $loaded = $ride->load(['driver', 'events']);
            RideUpdated::dispatch($loaded);

            return $loaded;
        });
    }

    private function assertDriverEligible(Driver $driver, Ride $ride): void
    {
        if (! $driver->is_active) {
            throw ApiException::driverNotEligible('This driver account is not active');
        }

        if ($driver->status === DriverStatus::OnTrip) {
            throw ApiException::driverBusy();
        }

        if ($driver->status !== DriverStatus::Available) {
            throw ApiException::driverNotEligible('Go online before accepting rides');
        }

        if (mb_strtolower($driver->city) !== mb_strtolower($ride->city)) {
            throw ApiException::driverNotEligible("This ride is in {$ride->city}");
        }

        if (! $driver->hasLocation()) {
            throw ApiException::driverNotEligible('Set your current location before accepting rides');
        }

        if (! $ride->category->canBeServedBy($driver->vehicle_category)) {
            throw ApiException::driverNotEligible(
                "A {$driver->vehicle_category->value} vehicle cannot serve a {$ride->category->value} ride",
            );
        }
    }

    private function resolveCancellingActor(Ride $ride, ?string $riderId, ?string $driverId): string
    {
        if ($riderId !== null && $riderId === $ride->rider_id) {
            return 'rider';
        }

        if ($driverId !== null && $driverId === $ride->driver_id) {
            return 'driver';
        }

        if ($driverId !== null && $ride->driver_id === null) {
            throw ApiException::forbidden('No driver is assigned to this ride yet');
        }

        // A rider may not cancel someone else's ride, and a driver who is not
        // assigned cannot cancel it either.
        throw ApiException::forbidden('You are not the rider or the assigned driver for this ride');
    }

    private function releaseDriver(Ride $ride, string $reason): void
    {
        if ($ride->driver_id === null) {
            return;
        }

        $this->setDriverStatus($ride->driver_id, DriverStatus::Available);

        DriverAssignment::query()
            ->where('ride_id', $ride->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => $reason]);
    }

    private function setDriverStatus(string $driverId, DriverStatus $status): void
    {
        Driver::query()->whereKey($driverId)->update(['status' => $status->value]);
    }

    /** @param array<string, mixed> $metadata */
    private function recordEvent(Ride $ride, string $type, string $actorType, ?string $actorId, array $metadata = []): void
    {
        RideEvent::create([
            'ride_id' => $ride->id,
            'event_type' => $type,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * The unique indexes are the last line of defence; when they fire we must
     * turn a database error into the same domain error the pre-checks throw,
     * otherwise the client would see a 500 for a perfectly normal race.
     */
    private function translateUniqueViolation(QueryException $exception, ?string $riderId, ?string $driverId = null): \Throwable
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        // 23505 is PostgreSQL unique violation; 1062 is MySQL duplicate entry error
        if ($sqlState !== '23505' && $driverCode !== 1062 && $sqlState !== '23000') {
            return $exception;
        }

        $message = $exception->getMessage();

        if (str_contains($message, 'rides_one_active_per_rider_idx') && $riderId !== null) {
            $active = Ride::query()
                ->where('rider_id', $riderId)
                ->whereIn('status', RideStatus::activeValues())
                ->first();

            return ApiException::activeRideExists($active->id ?? 'unknown');
        }

        if (str_contains($message, 'rides_one_active_per_driver_idx') && $driverId !== null) {
            return ApiException::driverBusy();
        }

        return $exception;
    }
}
