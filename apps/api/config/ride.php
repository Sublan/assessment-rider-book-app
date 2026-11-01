<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ride domain configuration
|--------------------------------------------------------------------------
|
| Everything pricing- and matching-related is environment driven so the same
| image can run different cities/tariffs. Money is always expressed in minor
| units (paisa, 1 PKR = 100 paisa) and never as a float, so fare arithmetic is
| exact.
|
*/

return [
    'default_city' => env('RIDE_DEFAULT_CITY', 'Lahore'),

    'pricing' => [
        'economy' => [
            'base_minor' => (int) env('FARE_ECONOMY_BASE_MINOR', 16000),
            'per_km_minor' => (int) env('FARE_ECONOMY_PER_KM_MINOR', 6500),
            'per_min_minor' => (int) env('FARE_ECONOMY_PER_MIN_MINOR', 1200),
            'minimum_minor' => (int) env('FARE_ECONOMY_MINIMUM_MINOR', 26000),
            'surge' => (float) env('SURGE_ECONOMY', 1.0),
            'vehicle_example' => 'Suzuki Alto, Cultus or similar',
        ],
        'comfort' => [
            'base_minor' => (int) env('FARE_COMFORT_BASE_MINOR', 26000),
            'per_km_minor' => (int) env('FARE_COMFORT_PER_KM_MINOR', 9800),
            'per_min_minor' => (int) env('FARE_COMFORT_PER_MIN_MINOR', 1800),
            'minimum_minor' => (int) env('FARE_COMFORT_MINIMUM_MINOR', 42000),
            'surge' => (float) env('SURGE_COMFORT', 1.15),
            'vehicle_example' => 'Toyota Yaris, Honda City or similar',
        ],
    ],

    'matching' => [
        'avg_speed_kmh' => (float) env('RIDE_AVG_SPEED_KMH', 22),
        'approach_speed_kmh' => (float) env('RIDE_APPROACH_SPEED_KMH', 18),
        'dispatch_overhead_minutes' => (int) env('RIDE_DISPATCH_OVERHEAD_MINUTES', 3),
        'max_candidates' => (int) env('RIDE_MAX_CANDIDATES', 5),
        'min_trip_meters' => (int) env('RIDE_MIN_TRIP_METERS', 250),
        'max_trip_meters' => (int) env('RIDE_MAX_TRIP_METERS', 120000),
    ],

    /*
    | How long an idempotency key is honoured. Rows older than this are pruned
    | by the `rides:prune-idempotency-keys` scheduled command, which keeps the
    | table bounded without ever letting a long-delayed retry create a duplicate.
    */
    'idempotency_ttl_hours' => (int) env('RIDE_IDEMPOTENCY_TTL_HOURS', 24),
];
