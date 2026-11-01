<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Driver;
use App\Services\DriverMatcher;
use Illuminate\Support\Collection;

final class DriverPresenter
{
    /** @return array<string, mixed> */
    public static function present(Driver $driver): array
    {
        return [
            ...RidePresenter::driver($driver),
            'isActive' => $driver->is_active,
            'location' => $driver->hasLocation()
                ? ['lat' => $driver->current_lat, 'lng' => $driver->current_lng]
                : null,
        ];
    }

    /**
     * @param  list<array{driver: Driver, distance_meters: int, eta_minutes: int}>  $ranked
     * @return list<array<string, mixed>>
     */
    public static function candidates(array $ranked): array
    {
        return array_map(static fn (array $candidate): array => [
            ...self::present($candidate['driver']),
            'distanceMeters' => $candidate['distance_meters'],
            'etaMinutes' => $candidate['eta_minutes'],
        ], $ranked);
    }

    /** @param Collection<int, Driver> $drivers */
    public static function collection(Collection $drivers): array
    {
        return $drivers->map(static fn (Driver $driver) => self::present($driver))->values()->all();
    }

    public static function matcher(): DriverMatcher
    {
        return app(DriverMatcher::class);
    }
}
