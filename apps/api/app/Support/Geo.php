<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Distance and ETA maths, deliberately pure and dependency free so it can be
 * unit tested without a database or an HTTP kernel.
 */
final class Geo
{
    private const EARTH_RADIUS_METERS = 6_371_008.8;

    /**
     * Straight-line distance adjusted by a road detour factor. A production
     * system would use a routing engine; this keeps fares and ETAs plausible on
     * a static map instead of systematically under-charging every trip.
     */
    public const ROAD_DETOUR_FACTOR = 1.28;

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($h)));
    }

    public static function roadDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        return (int) round(self::haversineMeters($lat1, $lng1, $lat2, $lng2) * self::ROAD_DETOUR_FACTOR);
    }

    public static function durationSecondsFor(int $distanceMeters, float $avgSpeedKmh): int
    {
        $metersPerSecond = ($avgSpeedKmh * 1000) / 3600;

        return max(60, (int) round($distanceMeters / $metersPerSecond));
    }

    /** Minutes until the driver reaches the rider: driving time plus dispatch overhead. */
    public static function approachEtaMinutes(int $distanceMeters, float $approachSpeedKmh, int $overheadMinutes): int
    {
        $metersPerSecond = ($approachSpeedKmh * 1000) / 3600;

        return max(1, (int) ceil($distanceMeters / $metersPerSecond / 60) + $overheadMinutes);
    }
}
