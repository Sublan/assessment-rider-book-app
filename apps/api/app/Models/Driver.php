<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriverStatus;
use App\Enums\RideCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class Driver extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;

    protected $table = 'drivers';

    protected $fillable = [
        'full_name',
        'phone',
        'city',
        'vehicle_category',
        'vehicle_model',
        'vehicle_plate',
        'rating',
        'status',
        'is_active',
        'current_lat',
        'current_lng',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'vehicle_category' => RideCategory::class,
            'rating' => 'float',
            'is_active' => 'bool',
            'current_lat' => 'float',
            'current_lng' => 'float',
        ];
    }

    /** @return HasMany<Ride, $this> */
    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class);
    }

    public function hasLocation(): bool
    {
        return $this->current_lat !== null && $this->current_lng !== null;
    }
}
