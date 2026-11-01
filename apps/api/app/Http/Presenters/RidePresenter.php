<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Driver;
use App\Models\Ride;

/**
 * Wire format for a ride.
 *
 * Kept as a plain static transformer instead of a framework JsonResource so the
 * exact same array can be (a) returned by a controller and (b) stored as the
 * idempotent response snapshot, and so it can be asserted in a unit test without
 * an HTTP kernel.
 */
final class RidePresenter
{
    /** @return array<string, mixed> */
    public static function present(Ride $ride): array
    {
        return [
            'id' => $ride->id,
            'status' => $ride->status->value,
            'category' => $ride->category->value,
            'city' => $ride->city,
            'riderId' => $ride->rider_id,
            'driver' => $ride->driver !== null ? self::driver($ride->driver) : null,
            'pickup' => [
                'label' => $ride->pickup_label,
                'lat' => $ride->pickup_lat,
                'lng' => $ride->pickup_lng,
            ],
            'destination' => [
                'label' => $ride->destination_label,
                'lat' => $ride->destination_lat,
                'lng' => $ride->destination_lng,
            ],
            'trip' => [
                'distanceMeters' => $ride->distance_meters,
                'durationSeconds' => $ride->duration_seconds,
                'pickupEtaMinutes' => $ride->pickup_eta_minutes,
            ],
            'fare' => [
                'currency' => $ride->currency,
                'totalMinor' => $ride->fare_total_minor,
                'totalPkr' => round($ride->fare_total_minor / 100, 2),
                'breakdown' => [
                    'baseMinor' => $ride->fare_base_minor,
                    'distanceMinor' => $ride->fare_distance_minor,
                    'timeMinor' => $ride->fare_time_minor,
                    'minimumMinor' => $ride->fare_minimum_minor,
                    'surgeMultiplier' => $ride->surge_multiplier,
                ],
            ],
            'cancellable' => $ride->status->isCancellable(),
            'timeline' => $ride->events->map(static fn ($event) => [
                'type' => $event->event_type,
                'actorType' => $event->actor_type,
                'at' => $event->created_at?->toIso8601String(),
                'metadata' => $event->metadata ?? [],
            ])->values()->all(),
            'requestedAt' => $ride->requested_at?->toIso8601String(),
            'acceptedAt' => $ride->accepted_at?->toIso8601String(),
            'completedAt' => $ride->completed_at?->toIso8601String(),
            'cancelledAt' => $ride->cancelled_at?->toIso8601String(),
            'cancelledBy' => $ride->cancelled_by,
            'cancellationReason' => $ride->cancellation_reason,
            'createdAt' => $ride->created_at?->toIso8601String(),
            'updatedAt' => $ride->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function driver(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'fullName' => $driver->full_name,
            'vehicleCategory' => $driver->vehicle_category->value,
            'vehicleModel' => $driver->vehicle_model,
            'vehiclePlate' => $driver->vehicle_plate,
            'rating' => $driver->rating,
            'status' => $driver->status->value,
            'city' => $driver->city,
            'phone' => $driver->phone,
        ];
    }
}
