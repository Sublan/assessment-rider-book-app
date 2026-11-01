<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideCategory;
use App\Models\Driver;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * Driver selection.
 *
 * Eligibility is explicit and ordered from cheapest to most expensive check, so
 * a driver who is simply offline never triggers a geo calculation:
 *   1. account active          2. status available
 *   3. same city               4. vehicle category can serve the request
 *
 * Ordering is deterministic (distance, then rating, then id) which makes the
 * ranking reproducible in tests and avoids "which driver got it" flapping when
 * two drivers are equidistant.
 */
final class DriverMatcher
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param  Collection<int, Driver>  $drivers
     * @return list<array{driver: Driver, distance_meters: int, eta_minutes: int}>
     */
    public function rank(Collection $drivers, float $pickupLat, float $pickupLng, RideCategory $category, string $city): array
    {
        $ranked = [];

        foreach ($drivers as $driver) {
            if (! $this->isEligible($driver, $category, $city)) {
                continue;
            }

            $distance = (int) round(Geo::haversineMeters(
                $pickupLat,
                $pickupLng,
                (float) $driver->current_lat,
                (float) $driver->current_lng,
            ));

            $ranked[] = [
                'driver' => $driver,
                'distance_meters' => $distance,
                'eta_minutes' => Geo::approachEtaMinutes(
                    $distance,
                    (float) $this->config['approach_speed_kmh'],
                    (int) $this->config['dispatch_overhead_minutes'],
                ),
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            return $a['distance_meters'] <=> $b['distance_meters']
                ?: ((float) $b['driver']->rating <=> (float) $a['driver']->rating)
                ?: strcmp((string) $a['driver']->id, (string) $b['driver']->id);
        });

        return array_slice($ranked, 0, (int) $this->config['max_candidates']);
    }

    public function isEligible(Driver $driver, RideCategory $category, string $city): bool
    {
        if (! $driver->is_active) {
            return false;
        }
        if ($driver->status !== DriverStatus::Available) {
            return false;
        }
        if (mb_strtolower($driver->city) !== mb_strtolower($city)) {
            return false;
        }
        if ($driver->current_lat === null || $driver->current_lng === null) {
            return false;
        }

        return $category->canBeServedBy($driver->vehicle_category);
    }

    /** @param Collection<int, Driver> $drivers */
    public function nearestEtaMinutes(Collection $drivers, float $pickupLat, float $pickupLng, RideCategory $category, string $city): ?int
    {
        $ranked = $this->rank($drivers, $pickupLat, $pickupLng, $category, $city);

        return $ranked[0]['eta_minutes'] ?? null;
    }
}
