<?php

declare(strict_types=1);

namespace App\Enums;

enum RideCategory: string
{
    case Economy = 'economy';
    case Comfort = 'comfort';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    /**
     * A comfort vehicle can serve a comfort request and also an economy request;
     * an economy vehicle can never serve comfort.
     */
    public function canBeServedBy(self $driverVehicleCategory): bool
    {
        return $driverVehicleCategory === self::Comfort || $this === self::Economy;
    }
}
