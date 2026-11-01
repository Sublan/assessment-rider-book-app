<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverStatus;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Ride;
use App\Support\LahoreLocations;

/**
 * Driver availability management.
 *
 * Availability is owned by the driver ("I am online"), but the ride service is
 * the only writer of `on_trip`, so a driver cannot lie their way out of an
 * active assignment.
 */
final class DriverService
{
    public function setStatus(
        string $driverId,
        string $actingDriverId,
        DriverStatus $status,
        ?float $lat,
        ?float $lng,
    ): Driver {
        if ($driverId !== $actingDriverId) {
            throw ApiException::forbidden('A driver can only change their own availability');
        }

        $driver = Driver::query()->whereKey($driverId)->first()
            ?? throw ApiException::driverNotFound($driverId);

        if ($driver->status === DriverStatus::OnTrip) {
            throw ApiException::driverBusy();
        }

        if ($status === DriverStatus::OnTrip) {
            throw ApiException::driverNotEligible('Trips are assigned by accepting a ride request');
        }

        if ($status === DriverStatus::Available) {
            $lat ??= $driver->current_lat;
            $lng ??= $driver->current_lng;

            if ($lat === null || $lng === null) {
                throw ApiException::driverNotEligible('Share your location before going online');
            }

            if (! LahoreLocations::isInside($lat, $lng)) {
                throw ApiException::outsideServiceArea($lat, $lng);
            }
        }

        $driver->fill([
            'status' => $status->value,
            'current_lat' => $lat ?? $driver->current_lat,
            'current_lng' => $lng ?? $driver->current_lng,
        ])->save();

        return $driver;
    }

    /** @return \Illuminate\Support\Collection<int, Driver> */
    public function all(?string $status = null): \Illuminate\Support\Collection
    {
        return Driver::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderBy('full_name')
            ->get();
    }

    /** The ride a driver is currently bound to, if any. */
    public function activeRide(string $driverId): ?Ride
    {
        return Ride::query()
            ->where('driver_id', $driverId)
            ->where('status', 'accepted')
            ->first();
    }
}
