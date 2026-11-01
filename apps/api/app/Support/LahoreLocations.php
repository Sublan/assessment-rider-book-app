<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lahore service area.
 *
 * In production these come from a geocoder and are stored with a geohash/H3
 * cell; they are static here so the app runs without any third-party key. The
 * bounding box is used for server-side validation: a request from Karachi is
 * rejected before it can reach the matcher.
 */
final class LahoreLocations
{
    public const BOUNDS = [
        'min_lat' => 31.20,
        'max_lat' => 31.75,
        'min_lng' => 74.05,
        'max_lng' => 74.60,
    ];

    /** @return list<array{id: string, label: string, area: string, lat: float, lng: float}> */
    public static function all(): array
    {
        return [
            ['id' => 'gulberg-iii', 'label' => 'Gulberg III, Main Boulevard', 'area' => 'Gulberg', 'lat' => 31.5204, 'lng' => 74.3440],
            ['id' => 'liberty-market', 'label' => 'Liberty Market, Gulberg', 'area' => 'Gulberg', 'lat' => 31.5167, 'lng' => 74.3482],
            ['id' => 'model-town', 'label' => 'Model Town Link Road', 'area' => 'Model Town', 'lat' => 31.4850, 'lng' => 74.3250],
            ['id' => 'johar-town', 'label' => 'Johar Town, G Block', 'area' => 'Johar Town', 'lat' => 31.4697, 'lng' => 74.2728],
            ['id' => 'dha-phase-5', 'label' => 'DHA Phase 5, Khayaban-e-Bukhari', 'area' => 'DHA', 'lat' => 31.4697, 'lng' => 74.4123],
            ['id' => 'wapda-town', 'label' => 'Wapda Town, Roundabout', 'area' => 'Wapda Town', 'lat' => 31.4318, 'lng' => 74.2424],
            ['id' => 'fortress-stadium', 'label' => 'Fortress Stadium', 'area' => 'Cantt', 'lat' => 31.5418, 'lng' => 74.3403],
            ['id' => 'airport', 'label' => 'Allama Iqbal International Airport', 'area' => 'Airport', 'lat' => 31.5216, 'lng' => 74.4036],
            ['id' => 'bahria-town', 'label' => 'Bahria Town, Sector C', 'area' => 'Bahria Town', 'lat' => 31.3667, 'lng' => 74.1833],
            ['id' => 'raiwind', 'label' => 'Raiwind Road, Adda Plot', 'area' => 'Raiwind', 'lat' => 31.3226, 'lng' => 74.2215],
        ];
    }

    public static function isInside(float $lat, float $lng): bool
    {
        return $lat >= self::BOUNDS['min_lat']
            && $lat <= self::BOUNDS['max_lat']
            && $lng >= self::BOUNDS['min_lng']
            && $lng <= self::BOUNDS['max_lng'];
    }
}
