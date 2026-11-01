<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverStatus: string
{
    case Available = 'available';
    case OnTrip = 'on_trip';
    case Offline = 'offline';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
