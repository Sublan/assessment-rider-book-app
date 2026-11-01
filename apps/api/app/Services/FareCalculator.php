<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RideCategory;

/**
 * Fare calculation, isolated from HTTP and the database.
 *
 * fare = max(round((base + per_km * km + per_min * minutes) * surge), minimum)
 *
 * All arithmetic is on integer paisa; rounding happens exactly once at the end,
 * so quoting the same trip twice always yields the same total.
 */
final class FareCalculator
{
    /** @param array<string, array<string, mixed>> $pricing */
    public function __construct(private readonly array $pricing) {}

    /** @return array<string, mixed> */
    public function quote(RideCategory $category, int $distanceMeters, int $durationSeconds): array
    {
        $rates = $this->ratesFor($category);

        $base = (int) $rates['base_minor'];
        $distance = (int) round(((int) $rates['per_km_minor'] * $distanceMeters) / 1000);
        $time = (int) round(((int) $rates['per_min_minor'] * $durationSeconds) / 60);
        $subtotal = $base + $distance + $time;

        $surge = (float) $rates['surge'];
        $total = max((int) round($subtotal * $surge), (int) $rates['minimum_minor']);

        return [
            'currency' => 'PKR',
            'base_minor' => $base,
            'distance_minor' => $distance,
            'time_minor' => $time,
            'minimum_minor' => (int) $rates['minimum_minor'],
            'surge_multiplier' => $surge,
            'total_minor' => $total,
        ];
    }

    public function vehicleExample(RideCategory $category): string
    {
        return (string) $this->ratesFor($category)['vehicle_example'];
    }

    /** @return array<string, mixed> */
    private function ratesFor(RideCategory $category): array
    {
        return $this->pricing[$category->value] ?? throw new \InvalidArgumentException(
            "No pricing configured for category {$category->value}"
        );
    }
}
