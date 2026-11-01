<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideCategory;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Support\Geo;
use App\Support\LahoreLocations;

/**
 * Quotes a trip and ranks the drivers who could serve it.
 *
 * This is the single place that turns "two points + a category" into a fare,
 * an ETA and a candidate list, so the estimate endpoint and ride creation can
 * never disagree about the price the rider was shown.
 */
final class TripPlanner
{
    /** @param array<string, mixed> $matching */
    public function __construct(
        private readonly FareCalculator $fares,
        private readonly DriverMatcher $matcher,
        private readonly array $matching,
    ) {}

    /**
     * @return array{
     *     distanceMeters: int,
     *     durationSeconds: int,
     *     fare: array<string, mixed>,
     *     pickupEtaMinutes: int|null,
     *     availableDrivers: int,
     *     candidates: list<array{driver: Driver, distance_meters: int, eta_minutes: int}>
     * }
     */
    public function plan(
        RideCategory $category,
        float $pickupLat,
        float $pickupLng,
        float $destinationLat,
        float $destinationLng,
        string $city,
    ): array {
        foreach ([$pickupLat, $pickupLng, $destinationLat, $destinationLng] as $value) {
            if (! is_finite($value) || abs($value) > 180) {
                throw ApiException::validation('Coordinates must be finite numbers');
            }
        }

        if (! LahoreLocations::isInside($pickupLat, $pickupLng)) {
            throw ApiException::outsideServiceArea($pickupLat, $pickupLng);
        }

        if (! LahoreLocations::isInside($destinationLat, $destinationLng)) {
            throw ApiException::outsideServiceArea($destinationLat, $destinationLng);
        }

        $distance = Geo::roadDistanceMeters($pickupLat, $pickupLng, $destinationLat, $destinationLng);

        if ($distance < (int) $this->matching['min_trip_meters']) {
            throw ApiException::tripTooShort();
        }

        if ($distance > (int) $this->matching['max_trip_meters']) {
            throw ApiException::tripTooLong();
        }

        $duration = Geo::durationSecondsFor($distance, (float) $this->matching['avg_speed_kmh']);
        $candidates = $this->candidates($category, $pickupLat, $pickupLng, $city);

        return [
            'distanceMeters' => $distance,
            'durationSeconds' => $duration,
            'fare' => $this->fares->quote($category, $distance, $duration),
            'pickupEtaMinutes' => $candidates[0]['eta_minutes'] ?? null,
            'availableDrivers' => count($candidates),
            'candidates' => $candidates,
        ];
    }

    /**
     * @return list<array{driver: Driver, distance_meters: int, eta_minutes: int}>
     */
    public function candidates(RideCategory $category, float $lat, float $lng, string $city): array
    {
        /*
         * Eligibility filtering happens in SQL for the cheap predicates
         * (status/active/city) and in PHP for the category rule. In production
         * the last step would be a bounding-box/geohash prefilter in the
         * database, or a Redis GEO query, followed by the same ranking.
         */
        $drivers = Driver::query()
            ->where('status', DriverStatus::Available->value)
            ->where('is_active', true)
            ->whereRaw('lower(city) = lower(?)', [$city])
            ->get();

        return $this->matcher->rank($drivers, $lat, $lng, $category, $city);
    }
}
